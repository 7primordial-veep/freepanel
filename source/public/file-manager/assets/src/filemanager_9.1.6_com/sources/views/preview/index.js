import { JetView } from "webix-jet";
import InfoPreview from "./info";
import { getLastSelected } from "../../helpers/common";

export default class PreviewView extends JetView {
	config() {
		const _ = this.app.getService("locale")._;
		const compact = this.getParam("compact");
		const skin = webix.skin.$active;

		const toolbar = {
			view: "toolbar",
			localId: "toolbar",
			height: skin.toolbarHeight + 2,
			padding: { right: 6 },
			elements: [
				{
					view: "label",
					localId: "filename:label",
					css: "webix_fmanager_preview_name",
				},
				{
					view: "icon",
					icon: "wxi-download",
					css: "webix_fmanager_spec_icon",
					localId: "download",
					tooltip: _("Download file"),
					click: () => {
						this.app.callEvent("app:action", ["download", [this.FileInfo]]);
					},
				},
				{
					view: "icon",
					icon: "wxi-close",
					hidden: !compact,
					click: () => this.app.callEvent("app:action", ["toggle-preview"]),
				},
			],
		};

		const preview = {
			view: "proxy",
			minHeight: 413,
			borderless: true,
			body: {
				$subview: true,
				name: "preview",
			},
		};

		return {
			margin: 0.1,
			rows: [
				toolbar,
				{
					view: "scrollview",
					borderless: true,
					body: {
						type: "wide",
						margin: 10,
						rows: [preview, InfoPreview],
					},
				},
			],
		};
	}

	init() {
		this.on(this.getParam("state").$changes, "selectedItem", v => {
			let lastSelected = getLastSelected(v);

			this.ShowInfo(lastSelected);
			this.FileInfo = lastSelected;

			const downloadIcon = this.$$("download");
			if (lastSelected.type === "folder") {
				downloadIcon.hide();
			} else {
				downloadIcon.show();
			}
		});
	}

	ShowInfo(v) {
		let previewUrl = "preview.template";

		const player = this.app.config.player;
		if (player && (v.type === "audio" || v.type === "video")) {
			previewUrl = "preview.media";
		}

		if (v.type !== "empty") {
			this.$$("filename:label").setValue(v.value);
			this.$$("toolbar").show();
		} else {
			this.$$("toolbar").hide();
		}

		this.show(previewUrl, {
			target: "preview",
			params: {
				info: v,
			},
		});
	}
}
