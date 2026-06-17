import { JetView } from "webix-jet";

import FoldersTree from "../folders";

export default class SideTreeView extends JetView {
	config() {
		return {
			view: "sidemenu",
			width: 300,
			state: state => {
				const toolbarHeight = webix.skin.$active.toolbarHeight + 14;
				state.top = toolbarHeight;
				state.height -= toolbarHeight;
			},
			body: FoldersTree,
		};
	}
	IsVisible() {
		return this.getRoot().isVisible();
	}
	Show() {
		this.getRoot().show();
	}
}
