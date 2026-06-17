import { JetView } from "webix-jet";

export default class PreviewPopupView extends JetView {
	config() {
		return {
			view: "window",
			head: false,
			fullscreen: true,
			body: { $subview: true, branch: true, name: "preview" },
		};
	}
	IsVisible() {
		return this.getRoot().isVisible();
	}
	Show(params) {
		this.show("preview", {
			target: "preview",
			params: { state: params.state, compact: true },
		});
		this.getRoot().show();
	}
	Hide() {
		this.show("_blank", {
			target: "preview",
		});
		this.getRoot().hide();
	}
}
