import "@wbx/view-codemirror-editor";

import { JetView } from "webix-jet";
import { iterateAsync } from "helpers/common";
import hotkey from "jet-hotkey";

const typeHash = {
	css: ["css", "less"],
	go: ["go"],
	htmlmixed: ["html", "xml", "svg"],
	javascript: ["js", "mjs", "json", "ts", "coffee"],
	markdown: ["md"],
	php: ["php", "phtml", "php3", "php4", "php5", "php7", "php-s", "pht", "phar"],
	python: ["py", "pyc", "pyd", "pyo", "pyw", "pyz"],
	sql: ["sql", "sqlite3", "sqlite", "db"],
	yaml: ["yaml", "yml"],
	shell: ["sh"],
};

export default class EditorView extends JetView {
	config() {
		this._ = this.app.getService("locale")._;
		this.Files = this.getParam("files");

		const tabbar = {
			view: "tabbar",
			localId: "tabbar",
			css: "webix_fmanager_editor_tabs",
			borderless: true,
			tabMinWidth: 170,
			moreTemplate:
				"<span class='webix_icon webix_tabbar_more fmi-format-list-bulleted'></span>",
			tooltip: obj => obj.id,
			options: [],
			on: {
				onChange: id => this.OpenDoc(id),
				onBeforeTabClick: (id, e) => this.TabAction(id, e),
			},
			tabbarPopup: {
				css: "webix_gantt_editor_popup",
			},
		};

		const skin = webix.skin.$active;
		const toolbar = {
			view: "toolbar",
			css: "webix_fmanager_editor_bar",
			padding: { left: 4, right: 4, top: 0, bottom: 0 },
			height: skin.toolbarHeight + !(skin.toolbarHeight % 2),
			cols: webix.env.touch ? this.GetMobileControls() : [tabbar],
		};

		if (webix.env.touch) {
			toolbar.cols.push(this.GetCloseAll(true));
		} else {
			toolbar.cols.push({ width: 12 }, this.GetCloseAll());
		}

		return {
			type: "wide",
			rows: [
				toolbar,
				{
					view: "codemirror-editor",
					localId: "editor",
					theme: webix.skin.$name === "contrast" ? "monokai" : "default",
				},
			],
		};
	}

	/**
	 * Returns the Save button and file name label (in FM used for editor on touch devices)
	 * @returns {Array} - of objects with control configuration
	 */
	GetMobileControls() {
		return [
			{
				view: "button",
				localId: "saveBtn",
				type: "icon",
				label: this._("Save"),
				icon: "wxi-check",
				css: "webix_primary webix_fmanager_editor_save",
				width: 90,
				click: () => this.Save(),
			},
			{
				view: "label",
				label: " ",
				localId: "name",
				css: "webix_fmanager_editor_name",
			},
		];
	}

	/**
	 *
	 * @param {Boolean} mobile - true - returns the icon for mobile devices; false - desktop
	 * @returns {Object} with icon widget configuration
	 */
	GetCloseAll(mobile) {
		return {
			view: "icon",
			icon: mobile ? "wxi-close" : "fmi-exit-to-app",
			click: () => this.ConfirmAll(),
			tooltip: this._("Close the editor") + " (Esc)",
		};
	}

	init() {
		const editor = this.$$("editor");
		webix.extend(editor, webix.ProgressBar);
		editor.showProgress({
			type: "top",
		});

		if (webix.env.touch) {
			const file = this.Files[0];
			this.$$("name").setValue(this.GetFileLabel(file));
		}

		this._oldValue = {};
		this._changed = {};
		this._buffers = {}; // codemirror buffers

		editor.getEditor(true).then(editorObj => {
			iterateAsync(this.Files, file => {
				return this.app
					.getService("operations")
					.read(file.id)
					.then(text => this.AddDoc(file, text));
			})
				.then(() => {
					if (!webix.env.touch) {
						const tabbar = this.$$("tabbar");
						for (let i = 0; i < this.Files.length; ++i) {
							this.AddTab(tabbar, this.Files[i]);
						}
						tabbar.setValue(tabbar.config.options[0].id);
					}

					this.OpenDoc(this.Files[0].id);
				})
				.finally(() => {
					editor.hideProgress();
				});

			this.HandleChanges(editorObj);
		});

		// editor hotkeys for saving
		const ctrlKey = webix.env.isMac ? "COMMAND" : "CTRL";
		this.on(hotkey(), `${ctrlKey} + S`, (v, e) => {
			this.Save();
			webix.html.preventEvent(e);
		});
		this.on(hotkey(), `${ctrlKey} + Shift + S`, () => this.SaveAll());
		this.on(hotkey(), "ESC", () => this.ConfirmAll());
	}

	/**
	 * Adds a handler for changes in the editor (checks for changes comparing to the original text and updates the 'light bulb')
	 * @param {Object} editor - CodeMirror.Editor instance
	 */
	HandleChanges(editor) {
		editor.on("changes", () => {
			this.TextChanged(this.GetActiveFile());
		});
	}

	/**
	 * Saves a file contents to the buffer
	 * @param {Object} file - the file data object
	 * @param {string} text - the contents of the file
	 */
	AddDoc(file, text) {
		this._oldValue[file.id] = text;
		this._buffers[file.id] = CodeMirror.Doc(text, this.GetFileType(file));
	}

	/**
	 * Prepares a label with file name for tabs
	 * @param {Object} file - a file data object
	 * @param {Boolean} short - (optional) if true, only the file name (without directory) will be returned; if not passed/false, the full path to the file will be returned
	 * @returns {string}
	 */
	GetFileLabel(file, short) {
		return `<div class="filename">${this.ClipName(
			short ? file.value : file.id
		)}</div><span class="extension">.${file.$ext}</span>`;
	}

	/**
	 * Cuts off the file extension from its name/ID
	 * @param {string} path - the file ID or name
	 * @returns {string}
	 */
	ClipName(path) {
		if (!path) return "";
		const parts = path.split(".");
		return parts.slice(0, parts.length - 1).join(".");
	}

	/**
	 * Adds a tab to tabbar for each loaded file if there are several of them
	 * @param {Object} tabbar - this.$$("tabbar")
	 * @param {Object} file - a file data object
	 */
	AddTab(tabbar, file) {
		const icon = `<span class="webix_fmanager_tab_action webix_icon wxi-close" webix_tooltip="${this._(
			"Close this file"
		)}"></span>`;
		const tabContent = `<div class="tab_content">${this.GetFileLabel(
			file,
			true
		)}${icon}</div>`;

		const width =
			webix.html.getTextSize(file.value, "webix_item_tab").width + 90;
		tabbar.addOption({
			id: file.id,
			value: tabContent,
			css: "webix_fmanager_editor_tab",
			width: width > 250 ? 250 : width,
		});
	}

	/**
	 * Opens a file in the editor, the previous file stays in the buffer (in multi mode)
	 * @param {string} name - the file ID (the full path to the file, here serves as the key)
	 */
	OpenDoc(name) {
		this.$$("editor")
			.getEditor(true)
			.then(editor => {
				editor.swapDoc(this._buffers[name]);
				editor.focus();
			});
	}

	/**
	 * Closes the editor and returns to main FM view
	 */
	Back() {
		this.show("/top", { params: { state: this.getParam("state") } });
	}

	/**
	 * Opens a confirmation dialogue if the user decides to close the editor, but there are unsaved changes
	 * Closes the editor, if the user doesn't mind loosing changes or there are no unsaved changes
	 */
	ConfirmAll() {
		if (this.CheckChanges())
			webix
				.confirm({
					text: this._("Are you sure you want to exit without saving?"),
				})
				.then(() => this.Back());
		else this.Back();
	}

	/**
	 * Handles clicks on tab icons
	 * @param {string} id - the ID of a text file
	 * @param {MouseEvent} e - the browser click event
	 * @returns {Boolean} - the return value is used by inner Webix logic, see comments below
	 */
	TabAction(id, e) {
		// clicks on the More popup will not send the native event object + we need to just select the tab there anyway
		if (e) {
			const classes = e.target.className;
			const actionIcon =
				classes.indexOf("webix_icon") !== -1 &&
				id == this.$$("tabbar").getValue();

			if (actionIcon) {
				if (classes.indexOf("close") !== -1) {
					this.CloseTab(id);
				} else if (classes.indexOf("circle") !== -1) {
					this.ConfirmOne(id);
				}
			}

			return !actionIcon; // must return false if the action is to be executed;
			// otherwise Webix tabbar selection will happen and will ruin the work of logic behind tab option removal
		}

		return true;
	}

	/**
	 * Opens a confirmation dialogue if the user decides to close the currently active tab
	 * Will close the file anyway, but if the user clicks OK will save the file before that
	 * @param {string} id - the file ID
	 */
	ConfirmOne(id) {
		webix
			.confirm({
				text: this._("Save before closing?"),
			})
			.then(() => {
				this.Save(id).then(() => this.CloseTab(id));
			})
			.catch(() => {
				this.CloseTab(id);
			});
	}

	/**
	 * Checks if there are unsaved changes
	 * @returns {Boolean}
	 */
	CheckChanges() {
		for (let f in this._changed) {
			if (this._changed[f]) return true;
		}
		return false;
	}

	/**
	 * Saves all unsaved changes
	 * Note that the function inside iterateAsync must return a promise
	 */
	SaveAll() {
		const files = this.Files.filter(f => this._changed[f.id]);
		if (files.length) {
			iterateAsync(files, file => {
				return this.WriteFileContent(file.id);
			}).then(() => {
				this.ChangeTextState(false);
			});
		}
	}

	/**
	 * Saves the file in the currently active tab (multi mode) or the only file in single mode
	 * @param {string} id - the file ID
	 * @returns {Promise}
	 */
	Save(id) {
		if (!id) id = this.GetActiveFile();
		if (this._changed[id]) {
			return this.WriteFileContent(id).then(() => {
				this.ChangeTextState(false, id);
			});
		}
		return webix.promise.resolve();
	}

	/**
	 * Saves changes of 1 file
	 * @param {string} id - the file ID
	 * @returns {Promise} that resolves with a file data object
	 */
	WriteFileContent(id) {
		const content = this._buffers[id].getValue();
		return this.app
			.getService("operations")
			.write(id, content)
			.then(() => {
				this._oldValue[id] = content;
			});
	}

	/**
	 * Gets the ID of the currently active file
	 * @returns {string} - the file ID
	 */
	GetActiveFile() {
		return webix.env.touch ? this.Files[0].id : this.$$("tabbar").getValue();
	}

	/**
	 * Returns the file type of the given file
	 * @param {Object} file - a file data object
	 * @returns {string} - the file type accepted by CodeMirror
	 */
	GetFileType(file) {
		if (file.value === "Dockerfile") return "dockerfile";

		if (file.$ext) {
			for (var type in typeHash) {
				if (typeHash[type].indexOf(file.$ext) !== -1) {
					return type;
				}
			}
		}
		return "htmlmixed"; // the most versatile mode
	}

	/**
	 * Checks if the file text was changed by the latest edit (comparing with the latest save)
	 * and then turns on/off the 'light bulbs' on the Save button and on file tabs
	 * @param {string} file - a file ID
	 */
	TextChanged(file) {
		const isEqual = this._buffers[file] == this._oldValue[file];
		// if current state does not correspond to _changed flag
		this.ChangeTextState(!isEqual, file);
	}

	/**
	 * Turns on/off the 'light bulbs' on file tabs or Save button
	 * @param {Boolean} state - true if changed, false if unchanged
	 * @param {string} file - (optional) a file ID; if not passed, all files are marked as unchanged/saved
	 */
	ChangeTextState(state, file) {
		if (file && state === !!this._changed[file]) return;

		if (file) this._changed[file] = state;
		else this._changed = {};

		if (webix.env.touch) {
			this.ChangeButtonState(state);
		} else {
			this.ChangeTabsState(state, file);
		}
	}

	/**
	 * Turns on/off the 'light bulbs' on the Save button
	 * @param {Boolean} state - true if changed, false if unchanged
	 */
	ChangeButtonState(state) {
		const button = this.$$("saveBtn");
		button.config.icon = state ? "webix_fmanager_icon fmi-circle" : "wxi-check";
		button.refresh();
	}

	/**
	 * Turns on/off the 'light bulbs' on file tabs
	 * @param {Boolean} state - true if changed, false if unchanged
	 * @param {string} file - (optional) a file ID; if not passed, all files are marked as unchanged/saved
	 */
	ChangeTabsState(state, file) {
		const tabbar = this.$$("tabbar");
		if (file) {
			let tab = tabbar.getOption(file);
			this.ChangeTabState(tab, state);
		} else {
			const tabs = tabbar.config.options;
			for (let i = 0; i < tabs.length; ++i) {
				this.ChangeTabState(tabs[i], state);
			}
		}
		tabbar.refresh();
	}

	/**
	 * Turns on/off a 'light bulb' on 1 tab
	 * @param {Object} tab - a configuration object of a tab
	 * @param {Boolean} state - on/off (file changed/unchanged)
	 */
	ChangeTabState(tab, state) {
		tab.value = tab.value.replace(
			state ? "wxi-close" : "webix_fmanager_icon fmi-circle",
			state ? "webix_fmanager_icon fmi-circle" : "wxi-close"
		);
	}

	/**
	 * Closes a tab with file and removes it from the buffer
	 * Webix logic ensures that the next visible tab is selected
	 * If this is the last tab, the editor closes
	 * @param {string} id - the file ID
	 */
	CloseTab(id) {
		const tabbar = this.$$("tabbar");
		tabbar.removeOption(id);

		if (!tabbar.getValue()) {
			return this.Back();
		}

		delete this._buffers[id];
		delete this._changed[id];
		delete this._oldValue[id];
	}
}
