import { JetView } from "webix-jet";
import "@wbx/view-plyr";

export default class MediaPreview extends JetView {
	config() {
		return {
			rows: [
				{
					localId: "albumArt",
					hidden: true,
					css: "webix_fmanager_preview",
					template: `<img class="webix_fmanager_preview_icon" src="${this.app
						.getService("backend")
						.icon({ type: "audio" }, "big")}" />`,
				},
				{
					view: "plyr-player",
					css: "webix_fmanager_player",
					localId: "player",
					config: {
						controls: [
							"play-large",
							"play",
							"progress",
							"current-time",
							"mute",
							"volume",
						],
					},
				},
			],
		};
	}
	init() {
		const node = this.$$("albumArt").$view;
		webix.event(node, "dblclick", () => {
			const info = this.getParam("info");
			this.app.callEvent("app:action", ["open", [info]]);
		});
	}
	urlChange() {
		const info = this.getParam("info");
		this.ShowPreview(info);
	}
	ShowPreview(info) {
		if (!info) return;

		const url = this.app.getService("backend").directLink(info.id);
		this.SetMedia(url, info.type, info.$ext);

		const art = this.$$("albumArt");
		const player = this.$$("player");
		if (info.type === "audio") {
			player.config.height = 52;
			art.show();
		} else {
			player.config.height = 0;
			art.hide();
		}
	}
	SetMedia(src, type, ext) {
		const player = this.$$("player");

		player.define({
			source: {
				type,
				sources: [
					{
						src,
						type: `${type}/${ext}`,
					},
				],
			},
		});
	}
}
