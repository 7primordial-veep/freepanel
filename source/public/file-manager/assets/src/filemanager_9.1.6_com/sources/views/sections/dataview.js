import { JetView } from "webix-jet";
import hotkey from "jet-hotkey";

import { folderIcon } from "../../helpers/icons";

import ContextMenuView from "../menus/contextmenu";

export default class DataViewBase extends JetView {
	init() {
		this.State = this.getParam("state");
		this.Local = this.app.getService("local");

		this._Track = true;
		this.WTable.attachEvent("onAfterSelect", () => this.ShiftFocus());
		this.WTable.attachEvent("onSelectChange", () => {
			if (this._Track) {
				const newIDs = []
					.concat(
						this.WTable.getSelectedId(true).map(a => this.WTable.getItem(a))
					)
					.filter(a => a.value !== "..");
				if (newIDs.length || this.State.selectedItem.length) {
					if (!newIDs.length) newIDs.$noSelect = true;
					this.State.selectedItem = newIDs;
				}
			}
		});

		// refresh self on folder change
		this.on(this.State.$changes, "path", (v, o) => {
			if (!this.State.search) {
				if (this.State.source === "files") {
					if (!o) this.LoadData(v);
					else
						this.Local.folders().then(hierarchy => {
							this.State.selectedItem = this.GetPrevLocation(hierarchy, v, o);
							this.LoadData(v);
						});
				}
			} else if (o) {
				this.State.$batch({
					search: "",
					searchStats: null,
				});
			}
		});

		// refresh selection on localData.refresh
		this.on(this.WTable.data, "onStoreUpdated", (i, o, m) => {
			if (!m && this.WTable.count() && this.State.selectedItem.length) {
				this.SelectActive();
			}
		});

		// Shared hotkeys
		this.AddHotkeys();

		const compact = this.getParam("compact", true);
		this.Menu = this.ui(
			new (this.app.dynamic(ContextMenuView))(this.app, {
				compact,
				state: this.State,
			})
		);
		this.Menu.AttachTo(this.WTable.$view, e => {
			const id = this.WTable.locate(e);
			if (!id) return [{ type: "empty" }];

			const item = this.WTable.getItem(id);
			if (item.value == "..") return false;

			if (!this.WTable.isSelected(id)) this.WTable.select(id);

			let sel = this.WTable.getSelectedId(true);
			if (sel.length === 1) return [item];
			sel = sel.map(s => this.WTable.getItem(s)).filter(o => o.value != "..");
			return sel;
		});

		// hide contexts on scroll and drag
		this.on(this.WTable, "onAfterScroll", () => this.Menu.Hide());
		this.on(this.WTable, "onBeforeDrag", () => this.Menu.Hide());
	}

	/**
	 * Moves focus from one panel to the other in Total
	 */
	ShiftFocus() {
		if (this.getParam("trackActive", true)) this.State.isActive = true;
	}

	/**
	 * Opens a folder or file(s)
	 * @param {Array} items - selected files and folders
	 */
	Activate(items) {
		if (!items.length) return; // nothing selected - ignore

		if (items.length === 1) {
			const item = items[0];
			if (item.type === "folder") {
				this.ShowSubFolder(item.value === ".." ? item.value : item.id);
			} else {
				const operation =
					item.type === "code" && this.app.config.editor ? "edit" : "open";
				this.app.callEvent("app:action", [operation, items]);
			}
		} else {
			const operation = this.app.config.editor ? "edit" : "open";
			this.app.callEvent("app:action", [operation, items]);
		}
	}

	/**
	 * Opens a selected directory
	 * @param {string} id - the ID of the selected folder
	 */
	ShowSubFolder(id) {
		let path;
		if (id == "..") {
			const t = this.State.path.split("/");
			path = "/" + t.slice(1, t.length - 1).join("/");
		} else {
			path = id;
		}

		this.State.path = path;
	}

	/**
	 *
	 * @param {Object} hierarchy - folders TreeCollection
	 * @param {string} path - new opened path
	 * @param {string} oldPath - old opened path
	 * @returns {Array} with the previous opened path or an empty array
	 */
	GetPrevLocation(hierarchy, path, oldPath) {
		// oldPath does not exist if path was changed after a folder renamed
		if (oldPath === "/" || !hierarchy.exists(oldPath)) return [];

		if (path === "/") path = "../files";
		let id;
		while (oldPath) {
			id = oldPath;
			oldPath = hierarchy.getParentId(oldPath);
			if (oldPath === path) {
				const obj = hierarchy.getItem(id);
				return [
					{
						id: obj.id,
						value: obj.value,
						type: "folder",
						date: new Date(obj.date),
					},
				];
			}
		}
		return [];
	}

	/**
	 * Gets the target directory for a moved file or folder
	 * @param {string} target - the ID of a folder
	 * @returns {Object} - data object of the target folder
	 */
	GetTargetFolder(target) {
		const targetItem = target ? this.WTable.getItem(target) : null;
		const invalidTarget =
			!targetItem || targetItem.type !== "folder" || targetItem.value === "..";
		const targetFolder = invalidTarget ? this.State.path : targetItem.id;
		return targetFolder;
	}

	/**
	 * Selects the first item in the newly opened directory
	 */
	SelectActive() {
		const table = this.WTable;
		if (this.State.isActive !== false && !table.getSelectedId()) {
			let sel = this.State.selectedItem;

			for (let i = 0; i < sel.length; i++) {
				if (table.exists(sel[i].id)) table.select(sel[i].id, true);
			}

			if (!sel.$noSelect && !table.getSelectedId()) {
				const id = table.getFirstId();
				if (id) {
					table.select(id);
					sel.$noSelect = false;
				}
			}

			webix.delay(() => webix.UIManager.setFocus(table));
		}
	}

	/**
	 * Returns currently selected files and directories
	 * @returns {Array} - an array of file/directory data objects; an empty array if nothing is selected
	 */
	GetSelection() {
		return this.WTable.getSelectedId(true).map(file => {
			return this.WTable.getItem(file);
		});
	}

	/**
	 * Loads folders and files inside the opened directory
	 * @param {string} path - the currently opened directory
	 * @param {Boolean} search - pass true to signal that the data are loaded in response to a search query
	 */
	LoadData(path, search) {
		this._Track = false;
		const table = this.WTable;
		if (search) {
			table.clearAll();
			this.app
				.getService("backend")
				.search(path, search)
				.then(data => {
					// ignore data if search was cancelled quickly
					if (this.app) {
						for (let i = 0; i < data.length; ++i)
							this.Local.prepareData(data[i]);

						table.parse(data);

						this.GetSearchStats(data);
					}
				});
		} else {
			if (table.data.url !== path) {
				table.clearAll();
				this.Local.files(path).then(data => {
					// ignore data if either search or mode was changed quickly after path change
					if (this.app) {
						this.RenderData(data);
						this.SelectActive();
					}
				});
			}
		}
		this._Track = true;
	}

	/**
	 * Moves files and folders to a target directory
	 * @param {Array} source - an array of data objects of files and folders that are being moved
	 * @param {string} target - the ID of the target directory
	 * @returns {Boolean} - must always be false to cancel default Webix logic for drag-and-drop
	 */
	MoveFiles(source, target) {
		this.app
			.getService("operations")
			.move(source, this.GetTargetFolder(target));
		return false;
	}

	/**
	 * Returns a string with a file type icon
	 * @param {Object} obj - the data object of a file
	 * @returns {string} with HTML of a file type icon
	 */
	Icon(obj) {
		return `<img class="webix_fmanager_file-type-icon" src="${this.app
			.getService("backend")
			.icon(obj)}" />`;
	}

	/**
	 * Defines the looks of the dragged node for drag-and-drop of files and folders
	 * @param {Object} ctx - the context object of drag-and-drop Webix logic
	 */
	DragMarker(ctx) {
		const list = this.WTable;

		// remove ".."
		const parent = list.find(f => f.value === "..", true);
		if (parent) {
			const parentInd = ctx.source.indexOf(parent.id);
			if (parentInd !== -1) ctx.source.splice(parentInd, 1);
		}

		const files = ctx.source.length;
		if (!files) return false;

		if (files === 1) list.select(ctx.source[0]);

		const firstDragged = list.getItem(ctx.source[0]);
		let icon;
		if (firstDragged.type === "folder") {
			icon = folderIcon;
		} else {
			icon = this.Icon(firstDragged);
		}

		let html = "<div class='webix_fmanager_grid_drag_zone_list'>";
		html += `<div class="webix_fmanager_inner_drag_zone_list">${icon}${firstDragged.value}</div>`;
		html += "</div>";

		if (files > 1) {
			html = "<div class='webix_drag_main'>" + html + "</div>";
			html += "<div class='webix_badge'>" + files + "</div>";

			let multiple = "<div class='webix_drag_multiple'></div>";
			if (files > 2)
				multiple = "<div class='webix_drag_multiple_last'></div>" + multiple;

			html = multiple + html;
		}

		ctx.html = html;
	}

	/**
	 * Adds hotkeys for operations with directories and files
	 */
	AddHotkeys() {
		const ctrlKey = webix.env.isMac ? "COMMAND" : "CTRL";

		const operations = [
			{ key: "DELETE", oper: "delete" },
			{ key: "BACKSPACE", oper: "delete" },
			{ key: `${ctrlKey}+C`, oper: "copy" },
			{ key: `${ctrlKey}+X`, oper: "cut" },
			{ key: `${ctrlKey}+V`, oper: "paste" },
			{ key: `${ctrlKey}+R`, oper: "rename" },
			{ key: `${ctrlKey}+O`, oper: "open" },
			{ key: `${ctrlKey}+D`, oper: "download" },
			{ key: `${ctrlKey}+Alt+O`, oper: "locate" },
		];

		if (this.app.config.editor) {
			operations.push({ key: `${ctrlKey}+E`, oper: "edit" });
		}

		for (let i = 0; i < operations.length; ++i) {
			this.on(hotkey(this.getRoot()), operations[i].key, (v, e) => {
				const params = [operations[i].oper];
				this.app.callEvent("app:action", params);
				webix.html.preventEvent(e);
			});
		}

		this.on(hotkey(this.getRoot()), `${ctrlKey}+A`, (v, e) => {
			this.WTable.selectAll();
			webix.html.preventEvent(e);
		});
	}

	/**
	 * Counts found folders and files
	 * @param {Array} data - all directories and files that match a search query
	 */
	GetSearchStats(data) {
		const all = data.length;
		const folders = data.filter(i => i.type === "folder");
		this.State.searchStats = {
			folders: folders ? folders.length : 0,
			files: all - folders.length,
		};
	}

	/**
	 * Handles clicks on empty spaces in list or cards
	 * If click happened in Total in the inactive half, this method will shift focus to this part
	 */
	EmptyClick() {
		if (this.State.isActive !== false) this.WTable.unselectAll();
		else this.ShiftFocus();
	}
}
