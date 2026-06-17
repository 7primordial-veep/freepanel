import { JetView } from "webix-jet";
import { cardMode, gridMode, doubleMode } from "../helpers/icons";
import { capitalize } from "../helpers/common";
import hotkey from "jet-hotkey";

export default class TopBarView extends JetView {
	config() {
		const compact = this.getParam("compact");
		const skin = webix.skin.$active;
		const _ = this.app.getService("locale")._;

		const bar = {
			view: "toolbar",
			height: skin.toolbarHeight + 12,
			margin: 20,
			paddingY: 9,
			paddingX: 12,
			cols: [
				{
					view: "icon",
					icon: "webix_fmanager_icon fmi-file-tree",
					click: () => this.app.callEvent("app:action", ["toggle-folders"]),
					hidden: !compact,
				},
				{ view: "label", label: _("Files"), autowidth: true, hidden: compact },
				{
					view: "search",
					width: compact ? 0 : 300,
					localId: "search",
					placeholder: _("Search files and folders"),
				},
				{ hidden: compact },
				{
					view: "toggle",
					css: "webix_fmanager_preview_toggle",
					type: "icon",
					icon: "wxi-eye",
					tooltip: _("Preview"),
					width: skin.toolbarHeight < 37 ? 48 : 60,
					localId: "previewMode",
					click: () => this.app.callEvent("app:action", ["toggle-preview"]),
					hidden: compact,
				},
				{
					view: "segmented",
					width: 124,
					optionWidth: 40,
					localId: "modes",
					tooltip: conf => {
						switch (conf.id) {
							case "grid":
								return _("Table");
							case "cards":
								return _("Cards");
							case "double":
								return _("Total");
							default:
								return capitalize(conf.id + "");
						}
					},
					options: [
						{ value: gridMode, id: "grid" },
						{ value: cardMode, id: "cards" },
						{ value: doubleMode, id: "double" },
					],
				},
			],
		};

		return bar;
	}

	init() {
		const common = this.getParam("state");
		const modes = this.$$("modes");

		modes.attachEvent("onChange", v => {
			if (v) {
				common.$batch({
					mode: v,
					search: "",
				});
			}
		});
		this.on(common.$changes, "mode", v => {
			if (modes.getOption(v)) modes.setValue(v);
			else modes.setValue();
		});

		const search = this.$$("search");
		search.attachEvent("onTimedKeyPress", () => {
			common.search = search.getValue().trim();
		});

		this.on(common.$changes, "search", s => {
			s = s.trim();
			search.setValue(s);
		});

		// global hotkey to move focus to search input
		this.on(hotkey(), `${webix.env.isMac ? "COMMAND" : "CTRL"} + F`, (v, e) => {
			search.focus();
			webix.html.preventEvent(e);
		});
	}
}
