import { JetView } from "webix-jet";
import AddNewMenuView from "./menus/addnewmenu";
import ContextMenuView from "./menus/contextmenu";

import { formatTemplate } from "../helpers/common";

export default class FoldersView extends JetView {
	config() {
		const _ = this.app.getService("locale")._;

		const button = {
			view: "button",
			value: _("Add New"),
			inputWidth: 210,
			css: "webix_primary",
			align: "center",
			click: function() {
				this.$scope.Menu.Show(this.$view);
			},
		};

		const navbar = {
			view: "tree",
			localId: "tree",
			css: "webix_fmanager_tree",
			select: true,
			drag: "target",
			type: {
				template: (o, common) => {
					return `${common.icon(o)}${common.folder(o)} <span>${
						o.$level === 1 ? _(o.value) : o.value
					}</span>`;
				},
				folder: o => `<div class='webix_icon wxi-${o.icon || "folder"}'></div>`,
			},
			borderless: true,
			on: {
				onBeforeDrop: ctx => this.MoveFiles(ctx.source, ctx.target),
				onBeforeContextMenu: id => {
					if (id.substr(0, 2) !== "..") this.Tree.select(id);
					else return false;
				},
			},
		};

		const FSStats = {
			localId: "fs:stats",
			borderless: true,
			height: 68,
			css: "webix_fmanager_fsstats",
			template: obj => {
				const used = Math.floor((obj.used / obj.total) * 100);

				const activeSkinAccentColor = webix.skin.$active.timelineColor;
				const svg = `<svg width="100%" height="20px">
<rect y="1" rx="4" width="100%" height="8" style="fill:#DFE2E6;" />
<rect y="1" rx="4" width="${used ||
					0}%" height="8" style="fill:${activeSkinAccentColor};" /></svg>`;

				const label = `<div class="webix_fmanager_fsstats_label">${formatTemplate(
					obj.used || 0
				)} ${_("of")} ${formatTemplate(obj.total || 0)} ${_("used")}</div>`;

				return svg + label;
			},
		};

		const navpanel = {
			view: "proxy",
			body: {
				rows: [button, navbar, FSStats],
				padding: { top: 8, bottom: 4 },
			},
		};

		return navpanel;
	}

	init() {
		this.State = this.getParam("state");
		this.Tree = this.$$("tree");

		this.Ready = this.app
			.getService("local")
			.folders()
			.then(h => {
				this.Tree.sync(h);
				this.Subscribe();
				this.GetFsStats();
			});

		this.Menu = this.ui(AddNewMenuView);

		this.ContextMenu = this.ui(
			new (this.app.dynamic(ContextMenuView))(this.app, {
				compact: false,
				tree: true,
			})
		);
		this.ContextMenu.AttachTo(this.Tree, e => {
			const id = this.Tree.locate(e);
			return id ? [this.Tree.getItem(id)] : null;
		});

		this.on(this.app, "reload:fs:stats", () => this.GetFsStats(true));
	}

	Subscribe() {
		this.Tree.attachEvent("onAfterSelect", () => {
			let v = this.Tree.getSelectedId();
			if (v.substr(0, 2) == "..") {
				this.State.$batch({
					source: v.slice(3),
					path: "/",
				});
			} else {
				this.State.$batch({
					source: this.GetRootId(v).slice(3),
					path: v,
				});
			}
		});

		this.on(this.State.$changes, "path", v => {
			this.State.path = v;
			if (this.Tree.exists(v)) {
				this.Tree.select(v);
				var parent = this.Tree.getParentId(v);
				if (parent) this.Tree.open(parent);
				this.Tree.showItem(v);
			} else this.Tree.select("../" + this.State.source);
		});
	}

	GetFsStats(force) {
		this.app
			.getService("backend")
			.getInfo(force)
			.then(data => {
				this.$$("fs:stats").setValues(data.stats);
			});
	}

	MoveFiles(source, target) {
		if (target === "../files") target = "/";
		this.app.getService("operations").move(source, target);
		return false;
	}

	GetRootId(path) {
		let root;
		while (path) {
			root = path;
			path = this.Tree.getParentId(path);
		}
		return root;
	}
}
