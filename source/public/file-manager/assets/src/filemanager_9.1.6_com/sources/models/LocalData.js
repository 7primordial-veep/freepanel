import Cache from "./Cache";
import { ext } from "helpers/common";

export default class LocalData {
	constructor(app, size) {
		this.app = app;
		this.fscache = new Cache(size);
		this.hierarchy = new webix.TreeCollection();
		this.folders_ready = null;
	}

	defaultDir(id) {
		const dir = [];
		if (id !== "/")
			dir.push({
				type: "folder",
				value: "..",
				$row: (obj, common) => {
					return (
						common.backIcon() +
						`<span class='webix_fmanager_back'>${common.backLabel()}</span>`
					);
				},
			});
		return dir;
	}

	files(path, sync) {
		let fs = this.fscache.get(path);
		if (sync) return fs;
		if (fs) return Promise.resolve(fs);

		fs = new webix.DataCollection({
			scheme: {
				$change: this.prepareData,
				$serialize: this.serializeData,
			},
		});
		this.fscache.set(path, fs);
		return this.reload(fs, path);
	}

	serializeData(a) {
		if (a.value == "..") return false;
		else {
			const o = {};
			for (let f in a) {
				if (f.indexOf("$") !== 0) o[f] = a[f];
			}
			return o;
		}
	}

	prepareData(a) {
		if (typeof a.date === "number") a.date = new Date(a.date * 1000);

		if (a.type === "folder") a.$css = a.type;
		else a.$ext = ext(a.value);
	}

	reload(fs, path) {
		return this.app
			.getService("backend")
			.files(path)
			.then(data => {
				fs.clearAll();
				fs.parse(this.defaultDir(path).concat(data));
				return fs;
			});
	}

	refresh(path) {
		const fs = this.fscache.get(path);
		if (fs) return this.reload(fs, path);
	}

	/**
	 * Adds a file or a directory to FM (add, copy and move operations)
	 * @param {string} id - the target directory
	 * @param {Object} item - the data object of the item
	 * @param {Boolean} dir - if true, the moved/copied folder will be checked for inner directories, and if any they will be added to the tree of folders
	 */
	addFile(id, item, dir) {
		var fs = this.fscache.get(id);

		if (fs) {
			if (!fs.exists(item.id)) {
				fs.add(item, this.getFsPosition(fs, item));
			}
		}

		if (item.type === "folder") {
			if (id === "/") id = "../files";
			this.hierarchy.add(item, null, id);

			if (dir)
				this.app
					.getService("backend")
					.folders(item.id)
					.then(
						data =>
							data.length && this.hierarchy.parse({ parent: item.id, data })
					);
		}
	}

	getFsPosition(fs, item) {
		if (item.type !== "folder") return -1;

		const d = fs.data;
		return d.order.findIndex(a => d.getItem(a).type !== "folder");
	}

	deleteFile(item) {
		const { fscache, hierarchy } = this;

		fscache.each(fs => {
			if (fs && fs.exists(item)) fs.remove(item);
		});

		fscache.delete(item);

		if (hierarchy.exists(item)) hierarchy.remove(item);
	}

	updateFile(oldId, data, newId) {
		const hierarchy = this.hierarchy;

		this.fscache.each(fs => {
			if (fs && fs.exists(oldId)) {
				fs.updateItem(oldId, data);
				if (newId && oldId != newId) fs.data.changeId(oldId, newId);
			}
		});

		if (hierarchy.exists(oldId)) {
			hierarchy.updateItem(oldId, data);
			if (newId && oldId != newId) {
				hierarchy.data.changeId(oldId, newId);
			}
		}
	}

	defaultTree() {
		return [{ value: "My Files", id: "../files", open: true }];
	}

	folders(force) {
		const hierarchy = this.hierarchy;

		if (force || !this.folders_ready) {
			this.folders_ready = this.app
				.getService("backend")
				.folders()
				.then(data => {
					hierarchy.clearAll();
					hierarchy.parse(this.defaultTree());
					hierarchy.parse({ parent: "../files", data });

					return hierarchy;
				});
		}

		return this.folders_ready;
	}
}
