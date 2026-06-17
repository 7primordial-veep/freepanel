import { JetView } from "webix-jet";

export default class PanelSearchView extends JetView {
	config() {
		this.State = this.getParam("state");
		const _ = this.app.getService("locale")._;

		const back = {
			view: "icon",
			icon: "wxi-angle-left",
			click: () => {
				this.State.$batch({
					search: "",
					searchStats: null,
				});
			},
		};

		const toolbar = {
			view: "toolbar",
			cols: [
				back,
				{
					localId: "header",
					type: "header",
					borderless: true,
					css: "webix_fmanager_path",
					template: obj => {
						const text = _("Search results in");
						const root = this.app.getService("backend").getRootName();
						let path = "";
						if (obj && obj.path && obj.path !== "/") {
							path = webix.template.escape(obj.path);
						}

						return `${text} ${root}${path}`;
					},
				},
				{},
			],
		};

		return {
			rows: [toolbar, { $subview: true, params: { state: this.State } }],
		};
	}
	init() {
		this.$$("header").setValues({ path: this.State.path });
	}
	ready() {
		this.on(this.State.$changes, "search", v => {
			v = v.trim();
			if (v) {
				this.State.selectedItem = [];
				this.getSubView().LoadData(this.State.path, v);
			}
		});
	}
}
