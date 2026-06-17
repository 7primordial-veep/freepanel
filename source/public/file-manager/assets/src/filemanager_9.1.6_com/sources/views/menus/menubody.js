import { JetView } from "webix-jet";

const FOLDER = 0x01;
const MANY = 0x02;
const TEXT = 0x04;
const VIEW = 0x08;
const FULL = 0x10;
const TREE = 0x20;
const SEARCH = 0x40;
const EMPTY = 0x80;

export default class MenuBodyView extends JetView {
	constructor(app, config) {
		super(app);
		this.compact = !!config.compact;
		this.tree = !!config.tree;
	}
	config() {
		const _ = this.app.getService("locale")._;

		const options = [
			{
				id: "download",
				value: _("Download"),
				icon: "wxi-download",
				show: TREE,
				hotkey: "Ctrl+D",
			},
			{
				$template: "Separator",
				show: TREE,
			},
			{
				id: "toggle-preview",
				value: _("Preview"),
				icon: "wxi-eye",
				show: FULL | FOLDER,
			},
			{
				id: "locate",
				value: _("Open item location"),
				icon: "wxi-folder",
				hotkey: "Ctrl+Alt+O",
				show: FOLDER | SEARCH,
			},
			{
				id: "open",
				value: _("Open"),
				icon: "wxi-folder-open",
				hotkey: "Ctrl+O",
				show: VIEW,
			},
			// edit option (5)
			{
				$template: "Separator",
				show: VIEW,
			},
			{
				id: "copy",
				value: _("Copy"),
				hotkey: "Ctrl+C",
				icon: "fmi-content-copy",
				show: FOLDER | MANY | TREE,
			},
			{
				id: "cut",
				value: _("Cut"),
				hotkey: "Ctrl+X",
				icon: "fmi-content-cut",
				show: FOLDER | MANY | TREE,
			},
			{
				id: "paste",
				value: _("Paste"),
				hotkey: "Ctrl+V",
				icon: "fmi-content-paste",
				show: FOLDER | TREE | EMPTY,
			},
			{
				$template: "Separator",
				show: FOLDER | MANY | TREE,
			},
			{
				id: "rename",
				value: _("Rename"),
				icon: "fmi-rename-box",
				hotkey: "Ctrl+R",
				show: FOLDER,
			},
			{
				id: "delete",
				value: _("Delete"),
				icon: "wxi-close",
				hotkey: "Del / &#8592;",
				show: FOLDER | MANY,
			},
		];

		if (this.app.config.editor) {
			options.splice(5, 0, {
				id: "edit",
				value: _("Edit"),
				icon: "wxi-pencil",
				hotkey: "Ctrl+E",
				show: TEXT | MANY,
			});
		}

		const menu = {
			view: "menu",
			css: "webix_fmanager_menu",
			layout: "y",
			autoheight: true,
			data: options,
			template: obj => {
				return (
					(obj.icon
						? `<span class="webix_list_icon webix_icon ${obj.icon}"></span>`
						: "") +
					obj.value +
					(obj.hotkey
						? `<span class="webix_fmanager_context-menu-hotkey">${obj.hotkey}</span>`
						: "")
				);
			},
			on: {
				onMenuItemClick: id => {
					this.app.callEvent("app:action", [id, this.Files]);
					this.app.callEvent("app:filemenu:click");
				},
			},
		};

		return menu;
	}
	FilterOptions(files) {
		this.Files = files;

		const file = files[0];
		const many = files.length > 1;

		const vtypes = ["image", "audio", "video", "code", "pdf"];
		const viewable = vtypes.find(t => t === file.type || t === file.$ext);
		const search = this.app.getState().search;

		const empty = file.type === "empty";

		this.getRoot().define("width", search && !many ? 270 : 200);

		this.getRoot().filter(o => {
			return !(
				(!(o.show & EMPTY) && empty) ||
				(many && !(o.show & MANY)) ||
				(file.type === "folder" && !(o.show & FOLDER)) ||
				(o.show & TEXT && file.type !== "code") ||
				(o.show & VIEW && !viewable) ||
				(o.show & FULL && !this.compact) ||
				(o.show & TREE && this.tree) ||
				(o.show & SEARCH && !search)
			);
		});
	}
}
