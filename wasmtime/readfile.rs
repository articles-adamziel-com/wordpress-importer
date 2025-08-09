use std::fs;

fn main() {
    let data = fs::read_to_string("/sample.txt").expect("read file");
    println!("{}", data.trim());
}
