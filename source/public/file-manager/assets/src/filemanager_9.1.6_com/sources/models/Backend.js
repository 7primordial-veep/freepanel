export default class Backend {
	constructor(app, url) {
		this.app = app;
		this._url = url;
		this._features = { preview: {}, meta: {} };
	}

	url(path) {
		return this._url + path;
	}

	search(id, search) {
		id = id || "/";
		return this._files(this.url("files"), { id, search });
	}

	files(id) {
		id = id || "/";
		return this._files(this.url("files"), { id });
	}

	_files(url, params) {
		const data = webix.ajax(url, params).then(res => res.json());
		return this.getInfo().then(() => data);
	}

	folders(id) {
		id = id || "/";
		const data = webix.ajax(this.url("folders"), { id });
		return data.then(a => a.json());
	}

	icon(obj, size) {
		return this.url(`icons/${size || "small"}/${obj.type}/${obj.$ext}.svg`);
	}

	upload() {
		return this.url("upload");
	}

	readText(id) {
		return webix.ajax(this.url("text"), { id }).then(data => data.text());
	}

	writeText(id, content) {
		return webix
			.ajax()
			.post(this.url("text"), {
				id,
				content,
			})
			.then(r => r.json());
	}

	directLink(id, download) {
		return this.url(
			`direct?id=${encodeURIComponent(id)}${download ? "&download=true" : ""}`
		);
	}

	previewURL(obj, width, height) {
		if (!this._features.preview[obj.type]) return this.icon(obj, "big");

		return this.url(
			`preview?width=${width}&height=${height}&id=${encodeURIComponent(obj.id)}`
		);
	}

	remove(id) {
		return webix
			.ajax()
			.post(this.url("delete"), {
				id,
			})
			.then(r => r.json());
	}

	makedir(id, name) {
		return webix
			.ajax()
			.post(this.url("makedir"), {
				id,
				name,
			})
			.then(r => r.json());
	}

	makefile(id, name) {
		return webix
			.ajax()
			.post(this.url("makefile"), {
				id,
				name,
			})
			.then(r => r.json());
	}

	copy(id, to) {
		return webix
			.ajax()
			.post(this.url("copy"), {
				id,
				to,
			})
			.then(r => r.json());
	}

	move(id, to) {
		return webix
			.ajax()
			.post(this.url("move"), {
				id,
				to,
			})
			.then(r => r.json());
	}

	rename(id, name) {
		return webix
			.ajax()
			.post(this.url("rename"), {
				id,
				name,
			})
			.then(r => r.json());
	}

	getRootName() {
		const _ = this.app.getService("locale")._;
		return _("My Files");
	}

	getMeta(obj) {
		if (!this._features.meta[obj.type]) return false;

		return webix
			.ajax(this.url("meta"), {
				id: obj.id,
			})
			.then(r => r.json());
	}

	getInfo(force) {
		// cache global info
		if (this._info && !force) return this._info;

		return (this._info = webix.ajax(this.url("info")).then(resp => {
			resp = resp.json();
			this._features = resp.features;
			return resp;
		}));
	}
}
