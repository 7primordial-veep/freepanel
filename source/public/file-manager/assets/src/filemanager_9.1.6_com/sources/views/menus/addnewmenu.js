import { JetView } from "webix-jet";
import { prompt } from "../../helpers/prompt";
import { fileNameSelectMask, safeName } from "../../helpers/common";

export default class AddNewMenuView extends JetView {
	config() {
		const _ = this.app.getService("locale")._;
		return {
			view: "popup",
			width: 198,
			body: {
				view: "menu",
				autoheight: true,
				layout: "y",
				css: "webix_fmanager_add_new_menu",
				data: [
					{
						id: "makefile",
						value: _("Add new file"),
						icon: "webix_fmanager_icon fmi-file-plus-outline",
					},
					{
						id: "makedir",
						value: _("Add new folder"),
						icon: "webix_fmanager_icon fmi-folder-plus-outline",
					},
					{
						id: "upload",
						value: _("Upload file"),
						icon: "webix_fmanager_icon fmi-file-upload-outline",
					},
				],
				on: {
					onMenuItemClick: id => {
						if (id === "makefile" || id === "makedir") {
							const _ = this.app.getService("locale")._;
							prompt({
								text: _("Enter a new name"),
								button: _("Add"),
								selectMask: fileNameSelectMask,
								value: "New " + (id === "makefile" ? "file.txt" : "folder"),
							}).then(name => {
								this.app.callEvent("app:action", [id, safeName(name)]);
							});
						} else {
							this.app.callEvent("app:action", [id]);
						}
						this.getRoot().hide();
					},
				},
			},
		};
	}
	Show(target) {
		this.getRoot().show(target, { x: 20 });
	}
}
