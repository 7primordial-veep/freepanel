import { JetView } from "webix-jet";

export default class TemplatePreview extends JetView {
	config() {
		return {
			view: "template",
			localId: "preview",
			css: "webix_fmanager_preview",
		};
	}
	init() {
		const node = this.getRoot().$view;
		webix.event(node, "dblclick", () => {
			const info = this.getParam("info");
			if (info.type === "code" && this.app.config.editor) {
				this.app.callEvent("app:action", ["edit", [info]]);
			} else if (info.type !== "folder" && info.type !== "empty") {
				this.app.callEvent("app:action", ["open", [info]]);
			}
		});
	}
	urlChange() {
		const info = this.getParam("info");
		this.ShowPreview(info);
	}
	ShowPreview(info) {
		const preview = this.$$("preview");

		if (info.type === "folder") {
			preview.setHTML(
				`<img class="webix_fmanager_preview_icon" src="${this.app
					.getService("backend")
					.icon({ type: "folder" }, "big")}" />`
			);
		} else if (info.type === "empty") {
			preview.setHTML(
				`<img class="webix_fmanager_preview_icon" src="${this.app
					.getService("backend")
					.icon({ type: "none" }, "big")}" />`
			);
		} else {
			let origin = this.app.getService("backend").previewURL(info, 464, 407);
			preview.setHTML(
				`<img style='width:100%; height:100%;' src='${origin}' onerror='this.style.display="none"'>`
			);
		}
	}
}
