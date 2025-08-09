/* wasmtime_ext.c
 *
 * PHP 8+ extension embedding Wasmtime.
 *
 * API:
 *   $inst = new Wasmtime\Instance(string $wasmOrPath, array $imports = [], array $opts = [])
 *     $imports[] = [
 *       'module'   => 'env',
 *       'name'     => 'log',
 *       'params'   => ['i32','i32'],     // supported: i32,i64,f32,f64
 *       'results'  => [],                // or ['i32'] / ['f64'] etc.
*       'callback' => function(int $a, int $b) { return null; },
 *     ];
 *     $opts['wasi'] = true; // optional
 *
 *   $inst->call(string $export, array $args = []): mixed
 *   $inst->exports(): array // [['name'=>'foo','kind'=>'func'|'memory'|'global'|'table'], ...]
 *
 * Build (example):
 *   phpize && ./configure CFLAGS="-O2" LDFLAGS="-lwasmtime" && make
 *
 * Notes:
 *   - i64 returns map to PHP int on 64-bit; on 32-bit they’re returned as strings.
 *   - Only numeric value types are bridged. Memory/table/global access is not included here.
 */

#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include <stddef.h>
#include <php.h>
#include <zend_API.h>
#include <zend_exceptions.h>
#include <ext/standard/info.h>
#include <zend_object_handlers.h>
#include <string.h>

#include <wasmtime.h>
#include <wasi.h>
#include <wasm.h>

#define PHP_WASMTIME_EXTNAME "wasmtime"
#define PHP_WASMTIME_VERSION "0.1.0"

ZEND_BEGIN_MODULE_GLOBALS(wasmtime)
ZEND_END_MODULE_GLOBALS(wasmtime)

static zend_class_entry *ce_wasmtime_instance;

/* ------------------ Helpers: error/trap to exception ------------------ */

static void php_wasmtime_throw_error(wasmtime_error_t *err)
{
	if (!err) return;
	wasm_name_t msg = WASM_EMPTY_VEC;
	wasmtime_error_message(err, &msg); /* docs: returns malloc-owned bytes */
	zend_throw_exception(zend_ce_exception,
	                     msg.size ? (const char*)msg.data : "wasmtime error",
	                     0);
	wasm_name_delete(&msg);
	wasmtime_error_delete(err);
}

static void php_wasmtime_throw_trap(wasm_trap_t *trap)
{
	if (!trap) return;
	wasm_message_t msg = WASM_EMPTY_VEC;
	wasm_trap_message(trap, &msg);
	zend_throw_exception(zend_ce_exception,
	                     msg.size ? (const char*)msg.data : "wasm trap",
	                     0);
	wasm_byte_vec_delete(&msg);
	wasm_trap_delete(trap);
}

/* ------------------ Kind conversions ------------------ */

static inline wasmtime_valkind_t to_wasmtime_kind(wasm_valkind_t k)
{
	switch (k) {
		case WASM_I32: return WASMTIME_I32;
		case WASM_I64: return WASMTIME_I64;
		case WASM_F32: return WASMTIME_F32;
		case WASM_F64: return WASMTIME_F64;
		default:       return (wasmtime_valkind_t)0xff;
	}
}

static inline wasm_valkind_t parse_valkind(const char *s, size_t len)
{
	if (len==3 && strncasecmp(s,"i32",3)==0) return WASM_I32;
	if (len==3 && strncasecmp(s,"i64",3)==0) return WASM_I64;
	if (len==3 && strncasecmp(s,"f32",3)==0) return WASM_F32;
	if (len==3 && strncasecmp(s,"f64",3)==0) return WASM_F64;
	return (wasm_valkind_t)0xff;
}

/* ------------------ Host import env ------------------ */

typedef struct {
	zval callback;                /* persistent zval holding the PHP callable */
	size_t nargs, nrets;
	wasm_valkind_t *param_kinds;  /* array[nargs] */
	wasm_valkind_t *ret_kinds;    /* array[nrets]  */
} php_wasmtime_host_env;

static void php_wasmtime_host_env_free(void *env)
{
	if (!env) return;
	php_wasmtime_host_env *e = (php_wasmtime_host_env*)env;
	zval_ptr_dtor(&e->callback);
	if (e->param_kinds) efree(e->param_kinds);
	if (e->ret_kinds)   efree(e->ret_kinds);
	efree(e);
}

/* Wasmtime host callback -> PHP callable */
static wasm_trap_t *php_wasmtime_host_trampoline(
	void *env,
	wasmtime_caller_t *caller, /* unused here */
	const wasmtime_val_t *args,
	size_t nargs,
	wasmtime_val_t *results,
	size_t nresults
) {
	php_wasmtime_host_env *e = (php_wasmtime_host_env*)env;
	(void)caller;

	/* Prepare PHP call */
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZVAL_UNDEF(&fci.function_name);
	ZVAL_COPY(&fci.function_name, &e->callback); /* inc ref */
	if (zend_fcall_info_init(&fci.function_name, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
		zval_ptr_dtor(&fci.function_name);
		return wasmtime_trap_new("invalid PHP callback", strlen("invalid PHP callback"));
	}

	zval *params = NULL;
	if (nargs > 0) {
		params = safe_emalloc(nargs, sizeof(zval), 0);
		for (size_t i = 0; i < nargs; i++) {
			switch (e->param_kinds[i]) {
				case WASM_I32: ZVAL_LONG(&params[i], (zend_long)args[i].of.i32); break;
				case WASM_I64:
#if ZEND_SIZEOF_LONG >= 8
					ZVAL_LONG(&params[i], (zend_long)args[i].of.i64);
#else
					{
						char buf[32];
						snprintf(buf, sizeof(buf), "%lld", (long long)args[i].of.i64);
						ZVAL_STRING(&params[i], buf);
					}
#endif
					break;
				case WASM_F32: ZVAL_DOUBLE(&params[i], (double)args[i].of.f32); break;
				case WASM_F64: ZVAL_DOUBLE(&params[i], (double)args[i].of.f64); break;
				default: ZVAL_NULL(&params[i]); break;
			}
		}
	}

	fci.size = sizeof(fci);
	fci.object = NULL;
	fci.param_count = (uint32_t)nargs;
	fci.params = params;
	zval retval;
	ZVAL_UNDEF(&retval);

	if (zend_call_function(&fci, &fcc) != SUCCESS) {
		if (params) {
			for (size_t i=0;i<nargs;i++) zval_ptr_dtor(&params[i]);
			efree(params);
		}
		zval_ptr_dtor(&fci.function_name);
		return wasmtime_trap_new("PHP callback failed to invoke", strlen("PHP callback failed to invoke"));
	}

	if (params) {
		for (size_t i=0;i<nargs;i++) zval_ptr_dtor(&params[i]);
		efree(params);
	}
	zval_ptr_dtor(&fci.function_name);

	/* Marshal return(s) */
	if (nresults == 0) {
		zval_ptr_dtor(&retval);
		return NULL;
	}

	if (nresults == 1) {
		switch (e->ret_kinds[0]) {
			case WASM_I32:
				convert_to_long(&retval);
				results[0].kind = WASMTIME_I32;
				results[0].of.i32 = (int32_t)Z_LVAL(retval);
				break;
			case WASM_I64:
#if ZEND_SIZEOF_LONG >= 8
				convert_to_long(&retval);
				results[0].kind = WASMTIME_I64;
				results[0].of.i64 = (int64_t)Z_LVAL(retval);
#else
				/* Accept string or int; parse if needed */
				if (Z_TYPE(retval) == IS_STRING) {
					long long v = 0;
					sscanf(Z_STRVAL(retval), "%lld", &v);
					results[0].kind = WASMTIME_I64;
					results[0].of.i64 = (int64_t)v;
				} else {
					convert_to_long(&retval);
					results[0].kind = WASMTIME_I64;
					results[0].of.i64 = (int64_t)Z_LVAL(retval);
				}
#endif
				break;
			case WASM_F32:
				convert_to_double(&retval);
				results[0].kind = WASMTIME_F32;
				results[0].of.f32 = (float)Z_DVAL(retval);
				break;
			case WASM_F64:
				convert_to_double(&retval);
				results[0].kind = WASMTIME_F64;
				results[0].of.f64 = (double)Z_DVAL(retval);
				break;
			default:
				zval_ptr_dtor(&retval);
				return wasmtime_trap_new("unsupported return type", strlen("unsupported return type"));
		}
		zval_ptr_dtor(&retval);
		return NULL;
	}

	/* Multiple returns -> expect PHP array with correct arity */
	if (Z_TYPE(retval) != IS_ARRAY) {
		zval_ptr_dtor(&retval);
		return wasmtime_trap_new("host callback must return array for multi-result", strlen("host callback must return array for multi-result"));
	}
	HashTable *ht = Z_ARRVAL(retval);
	if (zend_hash_num_elements(ht) != nresults) {
		zval_ptr_dtor(&retval);
		return wasmtime_trap_new("host callback result arity mismatch", strlen("host callback result arity mismatch"));
	}
	zval *zv;
	size_t idx = 0;
	ZEND_HASH_FOREACH_VAL(ht, zv) {
		zval tmp;
		ZVAL_COPY(&tmp, zv);
		switch (e->ret_kinds[idx]) {
			case WASM_I32: convert_to_long(&tmp); results[idx].kind = WASMTIME_I32; results[idx].of.i32 = (int32_t)Z_LVAL(tmp); break;
			case WASM_I64:
#if ZEND_SIZEOF_LONG >= 8
				convert_to_long(&tmp); results[idx].kind = WASMTIME_I64; results[idx].of.i64 = (int64_t)Z_LVAL(tmp);
#else
				if (Z_TYPE(tmp) == IS_STRING) {
					long long v = 0; sscanf(Z_STRVAL(tmp), "%lld", &v);
					results[idx].kind = WASMTIME_I64; results[idx].of.i64 = (int64_t)v;
				} else {
					convert_to_long(&tmp);
					results[idx].kind = WASMTIME_I64; results[idx].of.i64 = (int64_t)Z_LVAL(tmp);
				}
#endif
				break;
			case WASM_F32: convert_to_double(&tmp); results[idx].kind = WASMTIME_F32; results[idx].of.f32 = (float)Z_DVAL(tmp); break;
			case WASM_F64: convert_to_double(&tmp); results[idx].kind = WASMTIME_F64; results[idx].of.f64 = (double)Z_DVAL(tmp); break;
			default: zval_ptr_dtor(&tmp); zval_ptr_dtor(&retval);
				return wasmtime_trap_new("unsupported return type", strlen("unsupported return type"));
		}
		zval_ptr_dtor(&tmp);
		idx++;
	} ZEND_HASH_FOREACH_END();
	zval_ptr_dtor(&retval);
	return NULL;
}

/* ------------------ Instance object ------------------ */

typedef struct {
	/* Wasmtime state */
	wasm_engine_t        *engine;   /* wasm.h */
	wasmtime_store_t     *store;    /* wasmtime */
	wasmtime_linker_t    *linker;   /* wasmtime */
	wasmtime_module_t    *module;   /* wasmtime */
	wasmtime_instance_t   instance; /* wasmtime, by value */

	/* For optional WASI */
	bool                  wasi_enabled;

	/* Zend object */
	zend_object std;
} php_wasmtime_instance;

static inline php_wasmtime_instance *php_wasmtime_instance_fetch(zend_object *obj)
{
	return (php_wasmtime_instance *)((char*)(obj) - XtOffsetOf(php_wasmtime_instance, std));
}

static void php_wasmtime_instance_free_obj(zend_object *object)
{
	php_wasmtime_instance *o = php_wasmtime_instance_fetch(object);

	/* Dropping the store releases functions and runs host env finalizers */
	if (o->store)   { wasmtime_store_delete(o->store); o->store = NULL; }
	if (o->module)  { wasmtime_module_delete(o->module); o->module = NULL; }
	if (o->linker)  { wasmtime_linker_delete(o->linker); o->linker = NULL; }
	if (o->engine)  { wasm_engine_delete(o->engine); o->engine = NULL; }

	zend_object_std_dtor(&o->std);
}

static zend_object *php_wasmtime_instance_create_obj(zend_class_entry *ce)
{
	php_wasmtime_instance *o = zend_object_alloc(sizeof(*o), ce);
	zend_object_std_init(&o->std, ce);
	object_properties_init(&o->std, ce);
	o->std.handlers = &std_object_handlers;
	return &o->std;
}

/* ------------------ Utils ------------------ */

static zend_string *php_wasmtime_slurp_file(const char *path)
{
        php_stream *s = php_stream_open_wrapper(path, "rb", STREAM_MUST_SEEK|REPORT_ERRORS, NULL);
        if (!s) return NULL;
        zend_string *buf = php_stream_copy_to_mem(s, PHP_STREAM_COPY_ALL, 0);
        php_stream_close(s);
        return buf;
}

static bool php_wasmtime_try_read_path(zend_string *in, zend_string **out_bytes)
{
	if (ZSTR_LEN(in) == 0) return false;
	/* heuristics: if the input looks like a readable file path, load it */
	if (VCWD_ACCESS(ZSTR_VAL(in), F_OK) == 0) {
		zend_string *bytes = php_wasmtime_slurp_file(ZSTR_VAL(in));
		if (bytes) { *out_bytes = bytes; return true; }
	}
	return false;
}

/* ------------------ methods ------------------ */

ZEND_BEGIN_ARG_INFO_EX(arginfo_wi_ctor, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, wasmOrPath, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, imports, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, options, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wi_call, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, exportName, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, args, IS_ARRAY, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wi_exports, 0, 0, 0)
ZEND_END_ARG_INFO()

/* Wasmtime\Instance::__construct */
PHP_METHOD(Wasmtime_Instance, __construct)
{
	zend_string *in;
	HashTable   *imports_ht = NULL;
	HashTable   *opts_ht = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_STR(in)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT_EX(imports_ht, 1, 0)
		Z_PARAM_ARRAY_HT_EX(opts_ht, 1, 0)
	ZEND_PARSE_PARAMETERS_END();

	php_wasmtime_instance *o = php_wasmtime_instance_fetch(Z_OBJ_P(ZEND_THIS));

	/* Prepare engine/store/linker */
	o->engine = wasm_engine_new();                                  /* wasm.h */
	if (!o->engine) { zend_throw_exception(zend_ce_exception, "engine_new failed", 0); return; }
	o->store  = wasmtime_store_new(o->engine, NULL, NULL);           /* wasmtime */
	if (!o->store)  { zend_throw_exception(zend_ce_exception, "store_new failed", 0); return; }
	o->linker = wasmtime_linker_new(o->engine);                      /* wasmtime */
	if (!o->linker) { zend_throw_exception(zend_ce_exception, "linker_new failed", 0); return; }

	/* Optional WASI */
	o->wasi_enabled = false;
	if (opts_ht) {
                zval *wasi = zend_hash_str_find(opts_ht, "wasi", sizeof("wasi")-1);
                if (wasi && zend_is_true(wasi)) {
#ifdef WASMTIME_FEATURE_WASI
                        HashTable *wasi_ht = (Z_TYPE_P(wasi) == IS_ARRAY) ? Z_ARRVAL_P(wasi) : NULL;
                        wasi_config_t *cfg = wasi_config_new();
                        if (!cfg) { zend_throw_exception(zend_ce_exception, "wasi_config_new failed", 0); return; }
                        /* inherit stdio/env/argv for simplicity */
                        wasi_config_inherit_stdin(cfg);
                        wasi_config_inherit_stdout(cfg);
                        wasi_config_inherit_stderr(cfg);
                        wasi_config_inherit_env(cfg);
                        wasi_config_inherit_argv(cfg);
                        if (wasi_ht) {
                                zval *dir = zend_hash_str_find(wasi_ht, "dir", sizeof("dir")-1);
                                if (dir && Z_TYPE_P(dir) == IS_STRING) {
                                        wasi_config_preopen_dir(cfg, Z_STRVAL_P(dir), "/");
                                }
                        }
                        wasmtime_context_t *cx = wasmtime_store_context(o->store);
                        wasmtime_error_t *e1 = wasmtime_context_set_wasi(cx, cfg); /* takes ownership */
                        if (e1) { php_wasmtime_throw_error(e1); return; }
                        wasmtime_error_t *e2 = wasmtime_linker_define_wasi(o->linker);
                        if (e2) { php_wasmtime_throw_error(e2); return; }
                        o->wasi_enabled = true;
#else
                        zend_throw_exception(zend_ce_exception, "WASI not enabled in wasmtime build", 0);
                        return;
#endif
                }
	}

	/* Read module bytes */
	zend_string *bytes = NULL;
	if (!php_wasmtime_try_read_path(in, &bytes)) {
		bytes = zend_string_copy(in);
	}
	wasmtime_error_t *err = NULL;

	/* Compile module */
	err = wasmtime_module_new(o->engine, (const uint8_t*)ZSTR_VAL(bytes), ZSTR_LEN(bytes), &o->module);
	zend_string_release(bytes);
	if (err) { php_wasmtime_throw_error(err); return; }

	/* Register imports */
	if (imports_ht) {
		zval *entry;
		ZEND_HASH_FOREACH_VAL(imports_ht, entry) {
			if (Z_TYPE_P(entry) != IS_ARRAY) continue;
			HashTable *ih = Z_ARRVAL_P(entry);
			zval *zmodule = zend_hash_str_find(ih, "module", sizeof("module")-1);
			zval *zname   = zend_hash_str_find(ih, "name",   sizeof("name")-1);
			zval *zparams = zend_hash_str_find(ih, "params", sizeof("params")-1);
			zval *zrets   = zend_hash_str_find(ih, "results",sizeof("results")-1);
			zval *zcb     = zend_hash_str_find(ih, "callback",sizeof("callback")-1);
			if (!zmodule || !zname || !zcb) {
				zend_throw_exception(zend_ce_exception, "import missing module/name/callback", 0);
				return;
			}
			if (Z_TYPE_P(zmodule)!=IS_STRING || Z_TYPE_P(zname)!=IS_STRING) {
				zend_throw_exception(zend_ce_exception, "module/name must be strings", 0);
				return;
			}
			/* Build functype */
			wasm_valtype_t **ptypes = NULL, **rtypes = NULL;
			size_t pn = 0, rn = 0;

			if (zparams && Z_TYPE_P(zparams)==IS_ARRAY) {
				pn = zend_hash_num_elements(Z_ARRVAL_P(zparams));
				if (pn) ptypes = safe_emalloc(pn, sizeof(*ptypes), 0);
				size_t i=0; zval *tv;
				ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zparams), tv) {
					zend_string *ts = zval_get_string(tv);
					wasm_valkind_t k = parse_valkind(ZSTR_VAL(ts), ZSTR_LEN(ts));
					zend_string_release(ts);
					if (k == 0xff) {
						if (ptypes) efree(ptypes);
						zend_throw_exception(zend_ce_exception, "unsupported param type", 0);
						return;
					}
					ptypes[i++] = wasm_valtype_new(k);
				} ZEND_HASH_FOREACH_END();
			}
			if (zrets && Z_TYPE_P(zrets)==IS_ARRAY) {
				rn = zend_hash_num_elements(Z_ARRVAL_P(zrets));
				if (rn) rtypes = safe_emalloc(rn, sizeof(*rtypes), 0);
				size_t i=0; zval *tv;
				ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(zrets), tv) {
					zend_string *ts = zval_get_string(tv);
					wasm_valkind_t k = parse_valkind(ZSTR_VAL(ts), ZSTR_LEN(ts));
					zend_string_release(ts);
					if (k == 0xff) {
						if (ptypes) { for (size_t j=0;j<pn;j++) wasm_valtype_delete(ptypes[j]); efree(ptypes); }
						if (rtypes) efree(rtypes);
						zend_throw_exception(zend_ce_exception, "unsupported result type", 0);
						return;
					}
					rtypes[i++] = wasm_valtype_new(k);
				} ZEND_HASH_FOREACH_END();
			}

			wasm_valtype_vec_t pvec = WASM_EMPTY_VEC, rvec = WASM_EMPTY_VEC;
			if (pn) wasm_valtype_vec_new(&pvec, pn, ptypes);
			if (rn) wasm_valtype_vec_new(&rvec, rn, rtypes);
			wasm_functype_t *fty = wasm_functype_new(&pvec, &rvec);

			if (ptypes) efree(ptypes);
			if (rtypes) efree(rtypes);

			php_wasmtime_host_env *env = ecalloc(1, sizeof(*env));
			ZVAL_COPY(&env->callback, zcb);
			env->nargs = pn;
			env->nrets = rn;
			env->param_kinds = pn ? safe_emalloc(pn, sizeof(wasm_valkind_t), 0) : NULL;
			env->ret_kinds   = rn ? safe_emalloc(rn, sizeof(wasm_valkind_t), 0) : NULL;
			for (size_t i=0;i<pn;i++) env->param_kinds[i] = wasm_valtype_kind(pvec.data[i]);
			for (size_t i=0;i<rn;i++) env->ret_kinds[i]   = wasm_valtype_kind(rvec.data[i]);

			err = wasmtime_linker_define_func(
				o->linker,
				Z_STRVAL_P(zmodule), Z_STRLEN_P(zmodule),
				Z_STRVAL_P(zname),   Z_STRLEN_P(zname),
				fty,
				php_wasmtime_host_trampoline,
				env,
				php_wasmtime_host_env_free
			);
			wasm_functype_delete(fty);
			if (err) { php_wasmtime_throw_error(err); return; }
		} ZEND_HASH_FOREACH_END();
	}

	/* Instantiate */
	wasm_trap_t *trap = NULL;
	err = wasmtime_linker_instantiate(
		o->linker,
		wasmtime_store_context(o->store),
		o->module,
		&o->instance,
		&trap
	);
	if (trap) { php_wasmtime_throw_trap(trap); return; }
	if (err)  { php_wasmtime_throw_error(err); return; }
}

PHP_METHOD(Wasmtime_Instance, call)
{
	zend_string *fname;
	HashTable *args_ht = NULL;
	ZEND_PARSE_PARAMETERS_START(1,2)
		Z_PARAM_STR(fname)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT_EX(args_ht, 1, 0)
	ZEND_PARSE_PARAMETERS_END();

	php_wasmtime_instance *o = php_wasmtime_instance_fetch(Z_OBJ_P(ZEND_THIS));
	wasmtime_context_t *cx = wasmtime_store_context(o->store);

	wasmtime_extern_t item;
	bool ok = wasmtime_instance_export_get(cx, &o->instance, ZSTR_VAL(fname), ZSTR_LEN(fname), &item);
	if (!ok || item.kind != WASMTIME_EXTERN_FUNC) {
		zend_throw_exception_ex(zend_ce_exception, 0, "export '%s' not found or not a function", ZSTR_VAL(fname));
		return;
	}

	wasm_functype_t *fty = wasmtime_func_type(cx, &item.of.func);
	const wasm_valtype_vec_t *p = wasm_functype_params(fty);
	const wasm_valtype_vec_t *r = wasm_functype_results(fty);

	size_t pn = p ? p->size : 0;
	size_t rn = r ? r->size : 0;

	if (args_ht == NULL && pn != 0) {
		wasm_functype_delete(fty);
		zend_throw_exception(zend_ce_exception, "arguments required", 0);
		return;
	}
	if (args_ht && zend_hash_num_elements(args_ht) != pn) {
		wasm_functype_delete(fty);
		zend_throw_exception(zend_ce_exception, "argument count mismatch", 0);
		return;
	}

	wasmtime_val_t *args = pn ? safe_emalloc(pn, sizeof(wasmtime_val_t), 0) : NULL;

	/* Build args from sequential array */
	if (pn) {
		size_t i = 0;
		zval *zv;
		ZEND_HASH_FOREACH_VAL(args_ht, zv) {
			wasm_valkind_t k = wasm_valtype_kind(p->data[i]);
			args[i].kind = to_wasmtime_kind(k);
			switch (k) {
				case WASM_I32: {
					zval tmp; ZVAL_COPY(&tmp, zv); convert_to_long(&tmp);
					args[i].of.i32 = (int32_t)Z_LVAL(tmp); zval_ptr_dtor(&tmp); break;
				}
				case WASM_I64: {
#if ZEND_SIZEOF_LONG >= 8
					zval tmp; ZVAL_COPY(&tmp, zv); convert_to_long(&tmp);
					args[i].of.i64 = (int64_t)Z_LVAL(tmp); zval_ptr_dtor(&tmp);
#else
					if (Z_TYPE_P(zv) == IS_STRING) {
						long long v=0; sscanf(Z_STRVAL_P(zv), "%lld", &v);
						args[i].of.i64 = (int64_t)v;
					} else {
						zval tmp; ZVAL_COPY(&tmp, zv); convert_to_long(&tmp);
						args[i].of.i64 = (int64_t)Z_LVAL(tmp); zval_ptr_dtor(&tmp);
					}
#endif
					break;
				}
				case WASM_F32: {
					zval tmp; ZVAL_COPY(&tmp, zv); convert_to_double(&tmp);
					args[i].of.f32 = (float)Z_DVAL(tmp); zval_ptr_dtor(&tmp); break;
				}
				case WASM_F64: {
					zval tmp; ZVAL_COPY(&tmp, zv); convert_to_double(&tmp);
					args[i].of.f64 = (double)Z_DVAL(tmp); zval_ptr_dtor(&tmp); break;
				}
				default:
					if (args) efree(args);
					wasm_functype_delete(fty);
					zend_throw_exception(zend_ce_exception, "unsupported param type", 0);
					return;
			}
			i++;
		} ZEND_HASH_FOREACH_END();
	}

	wasmtime_val_t *rets = rn ? safe_emalloc(rn, sizeof(wasmtime_val_t), 0) : NULL;
	wasm_trap_t *trap = NULL;
	wasmtime_error_t *err = wasmtime_func_call(cx, &item.of.func,
	                                           args, pn, rets, rn, &trap);
	if (args) efree(args);
	wasm_functype_delete(fty);
	if (trap) { if (rets) efree(rets); php_wasmtime_throw_trap(trap); return; }
	if (err)  { if (rets) efree(rets); php_wasmtime_throw_error(err); return; }

	/* Return values */
	if (rn == 0) {
		if (rets) efree(rets);
		RETURN_NULL();
	}
	if (rn == 1) {
		switch (rets[0].kind) {
			case WASMTIME_I32: RETURN_LONG((zend_long)rets[0].of.i32);
			case WASMTIME_I64:
#if ZEND_SIZEOF_LONG >= 8
				RETURN_LONG((zend_long)rets[0].of.i64);
#else
			{
				char buf[32];
				snprintf(buf, sizeof(buf), "%lld", (long long)rets[0].of.i64);
				RETVAL_STRING(buf);
				if (rets) efree(rets);
				return;
			}
#endif
			case WASMTIME_F32: RETURN_DOUBLE((double)rets[0].of.f32);
			case WASMTIME_F64: RETURN_DOUBLE((double)rets[0].of.f64);
			default: if (rets) efree(rets); zend_throw_exception(zend_ce_exception, "unsupported result type", 0); return;
		}
	}

	array_init_size(return_value, (uint32_t)rn);
	for (size_t i=0;i<rn;i++) {
		switch (rets[i].kind) {
			case WASMTIME_I32: add_next_index_long(return_value, (zend_long)rets[i].of.i32); break;
			case WASMTIME_I64:
#if ZEND_SIZEOF_LONG >= 8
				add_next_index_long(return_value, (zend_long)rets[i].of.i64);
#else
			{
				char buf[32]; snprintf(buf, sizeof(buf), "%lld", (long long)rets[i].of.i64);
				add_next_index_string(return_value, buf);
			}
#endif
				break;
			case WASMTIME_F32: add_next_index_double(return_value, (double)rets[i].of.f32); break;
			case WASMTIME_F64: add_next_index_double(return_value, (double)rets[i].of.f64); break;
			default: add_next_index_null(return_value); break;
		}
	}
	if (rets) efree(rets);
}

PHP_METHOD(Wasmtime_Instance, exports)
{
	ZEND_PARSE_PARAMETERS_NONE();
	php_wasmtime_instance *o = php_wasmtime_instance_fetch(Z_OBJ_P(ZEND_THIS));
	wasmtime_context_t *cx = wasmtime_store_context(o->store);

	array_init(return_value);
	for (size_t i = 0;; i++) {
		char *name = NULL; size_t name_len = 0;
		wasmtime_extern_t item;
		if (!wasmtime_instance_export_nth(cx, &o->instance, i, &name, &name_len, &item))
			break;
		zval row;
		array_init(&row);
		add_assoc_stringl(&row, "name", name ? name : "", name_len);
		const char *kind = "unknown";
		switch (item.kind) {
			case WASMTIME_EXTERN_FUNC:   kind = "func"; break;
			case WASMTIME_EXTERN_MEMORY: kind = "memory"; break;
			case WASMTIME_EXTERN_GLOBAL: kind = "global"; break;
			case WASMTIME_EXTERN_TABLE:  kind = "table"; break;
			default: break;
		}
		add_assoc_string(&row, "kind", (char*)kind);
		add_next_index_zval(return_value, &row);
		if (name) free(name); /* wasmtime allocates with malloc */
	}
}

/* ------------------ class/function tables ------------------ */

static const zend_function_entry wasmtime_instance_methods[] = {
	PHP_ME(Wasmtime_Instance, __construct, arginfo_wi_ctor,    ZEND_ACC_PUBLIC|ZEND_ACC_CTOR)
	PHP_ME(Wasmtime_Instance, call,        arginfo_wi_call,    ZEND_ACC_PUBLIC)
	PHP_ME(Wasmtime_Instance, exports,     arginfo_wi_exports, ZEND_ACC_PUBLIC)
	PHP_FE_END
};

PHP_MINIT_FUNCTION(wasmtime)
{
	zend_class_entry ce;
	INIT_NS_CLASS_ENTRY(ce, "Wasmtime", "Instance", wasmtime_instance_methods);
	ce_wasmtime_instance = zend_register_internal_class(&ce);
	ce_wasmtime_instance->create_object = php_wasmtime_instance_create_obj;

	return SUCCESS;
}

PHP_MINFO_FUNCTION(wasmtime)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "wasmtime support", "enabled");
	php_info_print_table_row(2, "version", PHP_WASMTIME_VERSION);
	php_info_print_table_end();
}

zend_module_entry wasmtime_module_entry = {
	STANDARD_MODULE_HEADER,
	PHP_WASMTIME_EXTNAME,
	NULL,                 /* functions */
	PHP_MINIT(wasmtime),  /* MINIT */
	NULL,                 /* MSHUTDOWN */
	NULL,                 /* RINIT */
	NULL,                 /* RSHUTDOWN */
	PHP_MINFO(wasmtime),  /* MINFO */
	PHP_WASMTIME_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_WASMTIME
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(wasmtime)
#endif