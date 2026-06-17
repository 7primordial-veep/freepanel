import { prompt } from "../helpers/prompt";
import { fileNameSelectMask, safeName } from "../helpers/common";

export default class Operations {
	/**
	 * @constructor
	 * @param {Object} app - the Jet app
	 * @param {Object} state - the reactive state
	 */
	constructor(app, state) {
		this.app = app;
		this.state = state;

		this.initEvents();
	}

	/**
	 * Returns the Backend service
	 * @returns {Object}
	 */
	backend() {
		return this.app.getService("backend");
	}

	/**
	 * Returns the Local service
	 * @returns {Object}
	 */
	local() {
		return this.app.getService("local");
	}

	/**
	 * Adds event handlers to app actions
	 */
	initEvents() {
		this.app.attachEvent("app:action", (name, info) => {
			switch (name) {
				case "open":
					this.open(info);
					break;
				case "download":
					this.download(info);
					break;
				case "edit":
					this.edit(info);
					break;
				case "delete":
					this.remove(info);
					break;
				case "makefile":
					this.makeFile(info);
					break;
				case "makedir":
					this.makeFolder(info);
					break;
				case "rename":
					this.rename(info);
					break;
				case "copy":
				case "cut":
					this.addToClipboard(name);
					break;
				case "paste":
					this.paste(info);
					break;
				case "locate":
					this.goUp(info);
			}
		});
	}

	/**
	 * Saves new content to a text file
	 * @param {string} id - the ID of a text file
	 * @param {string} content - the new content of the file
	 * @returns {Promise} that resolves with a file data object
	 */
	write(id, content) {
		return this.backend()
			.writeText(id, content)
			.then(data =>
				this.local().updateFile(id, { size: data.size, date: data.date })
			);
	}

	/**
	 * Opens a text file and reads its contents
	 * @param {string} id - the ID of a text file
	 * @returns {Promise} that resolves with the contents of a text file (string)
	 */
	read(id) {
		return this.backend().readText(id);
	}

	/**
	 * Creates a new file in the currently opened directory or in the root
	 * @param {string} name - the name of the new file
	 */
	makeFile(name) {
		const id = this.state.path || "/";
		this.backend()
			.makefile(id, name)
			.then(res => {
				if (!res.invalid) this.local().addFile(id, res);
			});
	}

	/**
	 * Creates a new folder in the currently opened directory or in the root
	 * @param {string} name - the name of the folder
	 */
	makeFolder(name) {
		const id = this.state.path || "/";
		this.backend()
			.makedir(id, name)
			.then(res => {
				if (!res.invalid) this.local().addFile(id, res);
			});
	}

	/**
	 * Opens the files in a text editor (files of type:"code")
	 * @param {Array} files - an array of file data objects
	 */
	edit(files) {
		const state = this.state;
		if (!files) files = state.selectedItem;
		files = files.filter(file => file.type === "code");
		if (files.length) {
			this.app.show("/editor", {
				params: {
					files,
					state,
				},
			});
		}
	}

	/**
	 * Opens files in new browser tabs
	 * @param {Array} files - an array of file data objects
	 */
	open(files) {
		if (!files) files = this.state.selectedItem;
		for (let i = 0; i < files.length; ++i) {
			if (files[i].type != "folder") {
				window.open(this.backend().directLink(files[i].id), "_blank");
			}
		}
	}

	/**
	 * Downloads a file
	 * @param {Array} files - an array with a file data object
	 */
	download(files) {
		if (!files) files = this.state.selectedItem;
		window.open(this.backend().directLink(files[0].id, true), "_self");
	}

	/**
	 * Removes files and files forever
	 * @param {Array} files - an array of folder/file data objects
	 * @returns {Promise} - empty promise
	 */
	remove(sfiles) {
		const state = this.state;

		const files = this.extractIds(sfiles || state.selectedItem);
		if (!files.length) return webix.promise.reject();

		const _ = this.app.getService("locale")._;
		return webix
			.confirm({
				title: _("Delete files"),
				text: this.removeConfirmMessage(sfiles || state.selectedItem),
				css: "webix_fmanager_confirm",
			})
			.then(() => {
				return this.app
					.getService("progress")
					.files(_("Deleting..."), files, f => {
						return this.backend()
							.remove(f)
							.then(res => {
								if (!res.invalid) {
									this.local().deleteFile(f);
									if (state.path === f) {
										state.path = "/";
									}
									// reset inactive path in 'double'
									this.app.callEvent("pathChanged", [f, "/"]);
									this.app.callEvent("reload:fs:stats", []);
								}
							});
					});
			})
			.then(() => {
				// relaunch search
				if (state.search) {
					state.search = state.search + " ";
				}
			});
	}

	/**
	 * Returns a message for a confirmation dialogue for file/folder removal
	 * @param {Array} files - an array of file/folder data objects
	 * @returns {string}
	 */
	removeConfirmMessage(files) {
		const _ = this.app.getService("locale")._;
		let message = `<div class="question">${_(
			"Are you sure you want to delete"
		)} ${files.length > 1 ? _("these items:") : _("this item:")}</div>`;

		let i = 0;
		const icon = "&#9679;&nbsp;";
		for (const limit = files.length < 6 ? files.length : 5; i < limit; ++i) {
			message += `<div class="item">${icon}${files[i].value}</div>`;
		}
		if (i < files.length) {
			message += `<div>${icon}${_("and")} ${files.length - i} ${_(
				"more file(s)"
			)}</div>`;
		}

		return message;
	}

	/**
	 * Renames a file/folder
	 * @param {Array} f - an array with a file/folder data object
	 */
	rename(f) {
		const state = this.state;
		const file = f ? f[0] : state.selectedItem[0];
		if (!file) return;

		const _ = this.app.getService("locale")._;
		const oldId = file.id;

		prompt({
			text: _("Enter a new name"),
			button: _("Rename"),
			value: file.value,
			selectMask: file.type !== "folder" ? fileNameSelectMask : null,
		}).then(name => {
			name = safeName(name);
			if (name && name !== file.value)
				this.backend()
					.rename(oldId, name)
					.then(res => {
						this.local().updateFile(
							oldId,
							{ value: res.id.split("/").pop() },
							res.id
						);

						if (file.type === "folder")
							this.reloadBranch(res.id).then(() => {
								// reset active path in 'list'/'cards' if folder was renamed
								if (state.path === oldId) {
									state.path = res.id;
								}
								// reset inactive path in 'double'
								this.app.callEvent("pathChanged", [oldId, res.id]);
							});

						// relaunch search
						if (state.search) {
							state.search = state.search + " ";
						}
					});
		});
	}

	/**
	 * Reloads the contents of a directory
	 * @param {string} id - the ID of a directory
	 * @returns {Promise}
	 */
	reloadBranch(id) {
		const hierarchy = this.local().hierarchy;
		return this.app
			.getService("backend")
			.folders(id)
			.then(data => {
				const toRemove = [];
				hierarchy.data.eachChild(id, obj => toRemove.push(obj.id));
				hierarchy.parse({ parent: id, data });
				hierarchy.remove(toRemove);
			});
	}

	/**
	 * Copies files and folders to a target directory
	 * @param {Array} files - an array of item IDs
	 * @param {string} targetFolder - the ID of the target directory
	 * @returns {Promise} - empty promise
	 */
	copy(files, targetFolder) {
		if (!files.length) return webix.promise.reject();
		const local = this.local();
		const _ = this.app.getService("locale")._;

		return this.app.getService("progress").files(_("Copying..."), files, f => {
			return this.backend()
				.copy(f, targetFolder)
				.then(res => {
					if (!res.invalid) {
						local.addFile(targetFolder, res, true);
					}
				});
		});
	}

	/**
	 * Moves files and folders to a target directory
	 * @param {Array} files - an array of item IDs
	 * @param {string} targetFolder - the ID of the target directory
	 * @returns {Promise} - empty promise
	 */
	move(files, targetFolder) {
		if (!files.length || !targetFolder) return webix.promise.reject();
		const local = this.local();

		// prevent move to the existing location
		const tfs = local.files(targetFolder, true);
		if (tfs) files = files.filter(a => !tfs.exists(a));
		// prevent moving folder into itself
		files = files.filter(a => a != targetFolder);
		if (!files.length) return webix.promise.reject();

		const _ = this.app.getService("locale")._;
		return this.app.getService("progress").files(_("Moving..."), files, f => {
			return this.backend()
				.move(f, targetFolder)
				.then(res => {
					if (!res.invalid) {
						local.deleteFile(f);
						local.addFile(targetFolder, res, true);

						if (this.state.path === f) {
							this.state.path = res.id;
						}
						// reset inactive path in 'double'
						this.app.callEvent("pathChanged", [f, res.id]);
					}
				});
		});
	}

	/**
	 * Returns file/folder IDs
	 * @param {Array} files - an array of file/folder data objects
	 * @returns {Array} - an array of IDs
	 */
	extractIds(files) {
		if (!files.length || typeof files[0] == "string") return files;

		const ids = [];
		for (let i = 0; i < files.length; ++i) {
			// for actions from context menu, filter out "back to parent"
			if (files[i].value !== "..") ids.push(files[i].id);
		}
		return ids;
	}
	/**
	 * Adds selected files and folders to the clipboard
	 * @param {string} mode - "cut" or "copy"
	 */
	addToClipboard(mode) {
		const files = this.state.selectedItem;
		if (files.length)
			this.state.clipboard = {
				files,
				mode: mode,
			};
	}

	/**
	 * Clears the clipboard
	 */
	clearClipboard() {
		this.state.clipboard = null;
	}

	/**
	 * Moves or copies files and folders from clipboard to the currently opened directory
	 * or currently selected folder if pasting command originated from context menu called on a folder
	 * @param {Array<Object>} files - (optional) the array of currently selected files
	 */
	paste(files) {
		const state = this.state;

		if (!state.clipboard) return;

		let target = state.path;
		if (files && files[0].type == "folder") target = files[0].id;

		if (state.clipboard.mode === "copy") {
			this.copy(this.extractIds(state.clipboard.files), target);
		} else if (state.clipboard.mode === "cut") {
			this.move(this.extractIds(state.clipboard.files), target);
		}
		this.clearClipboard();
	}

	/**
	 * Opens the directory that contains the selected item (used in search output view)
	 * @param {*} files
	 */
	goUp(files) {
		const state = this.state;
		if (state.search) {
			const file = files ? files[0] : state.selectedItem[0];
			if (!file) return;
			const up = file.id.split("/");

			const path = "/" + up.slice(1, up.length - 1).join("/");
			state.$batch({
				search: "",
				searchStats: null,
				path: path,
			});
		}
	}
}
