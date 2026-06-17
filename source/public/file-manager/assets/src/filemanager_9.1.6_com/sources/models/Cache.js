export default class Cache {
	constructor(limit) {
		this._store = new Map();
		this._limit = limit;
		this._i = 1;
	}
	each(code) {
		this._store.forEach(a => code(a.obj));
	}
	set(key, obj) {
		if (this._store.size >= this._limit) this.prune();

		this._store.set(key, {
			obj,
			key,
			t: this._i++,
		});
	}
	get(key) {
		const rec = this._store.get(key);
		if (!rec) return null;

		rec.t = this._i++;
		return rec.obj;
	}
	prune() {
		let temp = [];
		this._store.forEach(a => temp.push(a));
		temp = temp.sort((a, b) => (a.t > b.t ? -1 : 1));
		for (var i = Math.floor(this._limit / 2); i < temp.length; i++) {
			this._store.delete(temp[i].key);
		}
	}
	delete(key) {
		if (this._store.has(key)) this._store.delete(key);
	}
	clear() {
		this._store.clear();
	}
}
