/**
 * JavaScript side dispatcher for PHP JS-backed URL library.
 *
 * The host environment must register `onMessage` as the handler for
 * messages coming from PHP. Each message is a JSON string describing the
 * operation to perform.
 */

const objects = new Map();
let nextId = 1;

function alloc(obj) {
  const id = nextId++;
  objects.set(id, obj);
  return id;
}

export async function onMessage(msg) {
  const data = JSON.parse(msg);
  switch (data.target) {
    case 'URL':
      return handleURL(data);
    case 'URLSearchParams':
      return handleURLSearchParams(data);
    default:
      throw new Error('Unknown target: ' + data.target);
  }
}

function handleURL(data) {
  switch (data.op) {
    case 'new': {
      const obj = data.base ? new URL(data.url, data.base) : new URL(data.url);
      objects.set(data.id, obj);
      return '';
    }
    case 'get': {
      const obj = objects.get(data.id);
      const val = obj[data.prop];
      if (data.prop === 'searchParams') {
        return String(alloc(val));
      }
      return String(val);
    }
    case 'set': {
      const obj = objects.get(data.id);
      obj[data.prop] = data.value;
      return '';
    }
    case 'call': {
      const obj = objects.get(data.id);
      const res = obj[data.method](...(data.args || []));
      return String(res);
    }
  }
}

function handleURLSearchParams(data) {
  switch (data.op) {
    case 'new': {
      const obj = new URLSearchParams(data.init);
      objects.set(data.id, obj);
      return '';
    }
    case 'call': {
      const obj = objects.get(data.id);
      const method = data.method;
      const args = data.args || [];
      const res = obj[method].apply(obj, args);
      if (res === undefined) {
        return '';
      }
      if (Array.isArray(res)) {
        return JSON.stringify(res);
      }
      if (typeof res === 'boolean') {
        return res ? '1' : '';
      }
      return String(res);
    }
  }
}
