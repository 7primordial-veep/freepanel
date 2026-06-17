import { JetView } from "webix-jet";

import MenuBodyView from "./menubody";

export default class ContextMenuView extends JetView {
	constructor(app, config) {
		super(app);
		this._config = config;
	}
	config() {
		return {
			view: "context",
			body: {
				$subview: new (this.app.dynamic(MenuBodyView))(this.app, {
					...this._config,
				}),
				name: "options",
			},
			on: {
				onBeforeShow: e => {
					const files = this._Locate(e);
					if (files) this.getSubView("options").FilterOptions(files);
					else return false;
				},
			},
		};
	}
	init() {
		this.on(this.app, "app:filemenu:click", () => this.getRoot().hide());
	}
	AttachTo(master, locate) {
		this._Locate = locate;
		this.getRoot().attachTo(master);
	}
	Show(trg) {
		this.getRoot().show(trg, {
			x: -trg.offsetX,
			y: trg.target.offsetHeight - trg.offsetY,
		});
	}
	Hide() {
		this.getRoot().hide();
	}
}
