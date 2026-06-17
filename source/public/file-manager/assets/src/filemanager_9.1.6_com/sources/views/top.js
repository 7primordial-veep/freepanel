import { JetView } from "webix-jet";

import "../helpers/responsive";

import TopBar from "./topbar";
import FoldersTree from "./folders";

import SideTreeView from "./mobile/sidetree";
import PreviewPopupView from "./mobile/previewpopup";

export default class TopView extends JetView {
	config() {
		const fCompact = this.getParam("forceCompact");
		if (typeof fCompact !== "undefined") this.setParam("compact", fCompact);
		this.Compact = this.getParam("compact");

		const tree = {
			view: "proxy",
			batch: "tree",
			width: 240,
			minWidth: 240,
			maxWidth: 400,
			hidden: true,
			borderless: true,
			body: FoldersTree,
		};

		const panels = {
			type: "wide",
			localId: "folders",
			cols: [
				{ $subview: true, name: "center", branch: true },
				{
					view: "proxy",
					borderless: true,
					width: 470,
					hidden: true,
					localId: "r-side",
					body: {
						$subview: "",
						branch: true,
						name: "r-side",
					},
				},
			],
		};

		if (!this.Compact)
			panels.cols.unshift(tree, { view: "resizer", batch: "tree" });

		return {
			view: typeof fCompact === "undefined" ? "r-layout" : "layout",
			type: "wide",
			rows: [TopBar, panels, { $subview: true, popup: true, name: "popup" }],
		};
	}
	init() {
		// responsive UI
		const root = this.getRoot();
		if (root.sizeTrigger)
			root.sizeTrigger(
				this.app.config.compactWidth,
				mode => this.SetCompactMode(mode),
				!!this.Compact
			);

		const state = this.getParam("state");
		this.State = state;

		if (this.Compact) {
			this.SideTree = this.ui(SideTreeView);
			this.PreviewPopup = this.ui(PreviewPopupView);
		}

		this.on(this.app, "app:action", name => {
			switch (name) {
				case "toggle-preview":
					this.TogglePreview();
					break;
				case "toggle-folders":
					this.ToggleFolders();
					break;
			}
		});

		this.on(state.$changes, "mode", (v, o) => this.ShowMode(v, o));
		this.on(state.$changes, "search", v => this.ShowSearch(v));

		this.app
			.getService("progress")
			.handle(this.getRoot(), this.ShowProgress.bind(this));
	}

	ShowMode(v, o) {
		const folders = this.$$("folders");
		const pos = {
			target: "center",
			params: {
				state: this.getParam("state"),
				compact: this.Compact,
			},
		};

		switch (v) {
			case "grid":
			case "cards":
				folders.showBatch("tree");
				this.show("panel/" + (v == "grid" ? "list" : v), pos);
				break;
			case "double":
				folders.showBatch("");
				this.show("panel-double", pos);
				break;
			case "search": {
				this.PrevMode = this.PrevMode || o;
				folders.showBatch("");
				this.show("panel-search/cards", pos);
				break;
			}
		}
	}

	ShowProgress(params) {
		return this.show("./progress", {
			target: "popup",
			params,
		}).then(() => this.getSubView("popup"));
	}

	TogglePreview() {
		if (!this.Compact) {
			// invert value, to get new mode
			const side = this.$$("r-side");

			if (!side.isVisible()) {
				this.show("preview", {
					target: "r-side",
					params: { state: this.State },
				});
				side.show();
			} else {
				this.show("_blank", { target: "r-side" });
				side.hide();
			}
		} else {
			if (!this.PreviewPopup.IsVisible()) {
				this.PreviewPopup.Show({ state: this.State });
			} else {
				this.PreviewPopup.Hide();
			}
		}
	}

	ToggleFolders() {
		if (!this.SideTree.IsVisible()) {
			this.SideTree.Show();
		}
	}

	ShowSearch(value) {
		if (value) {
			this.State.mode = "search";
		} else {
			if (this.State.mode === "search")
				this.State.mode = this.PrevMode || "grid";
			this.PrevMode = "";
		}
	}

	SetCompactMode(mode) {
		this.setParam("compact", mode);
		this.refresh();
	}
}
