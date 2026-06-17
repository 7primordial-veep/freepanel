import DataViewBase from "views/sections/dataview";
import { menuIcon, folderCardIcon } from "../helpers/icons";

export default class CardsView extends DataViewBase {
	config() {
		const compact = this.getParam("compact", true);

		const smallSkin =
			webix.skin.$name === "mini" || webix.skin.$name === "compact";
		const itemSize = smallSkin
			? {
					height: 149,
					width: 181,
					padding: 16,
			  }
			: {
					height: 197,
					width: 236,
					padding: 20,
			  };

		return {
			view: "dataview",
			localId: "cards",
			drag: !compact,
			select: true,
			multiselect: true,
			css: "webix_noselect webix_fmanager_cards",
			template: obj => this.CardTemplate(obj),
			type: {
				...itemSize,
				type: "tiles",
			},
			on: {
				onItemDblClick: () => this.Activate(this.GetSelection()),
				onEnter: () => this.Activate(this.GetSelection()),
				onBeforeDrag: ctx => this.DragMarker(ctx),
				onBeforeDrop: ctx => this.MoveFiles(ctx.source, ctx.target),
			},
			onClick: {
				webix_fmanager_menu_icon: e => {
					this.Menu.Show(e);
				},
				webix_dataview: (e, id) => {
					if (!id) this.EmptyClick();
				},
			},
			onContext: {
				webix_dataview: (e, id) => {
					if (!id) this.EmptyClick();
				},
			},
		};
	}
	init() {
		this.WTable = this.$$("cards");
		super.init();
	}
	RenderData(data) {
		this.WTable.sync(data, () => this.WTable.filter(f => f.value !== ".."));
	}
	CardTemplate(obj) {
		const _ = this.app.getService("locale")._;

		let preview, panel;
		if (obj.type === "folder") {
			preview = `<div class="webix_fmanager_card_preview">${folderCardIcon}</div>`;

			panel = `<div class="webix_fmanager_card_panel">
		<span class="webix_fmanager_card_label">${_("Folder")}</span>
		<span class="webix_fmanager_card_name folder">${this.SearchTemplate(
			obj.value
		)}</span>${menuIcon}
		</div>`;
		} else {
			const skin = webix.skin.$active;
			const picSize = skin.listItemHeight < 29 ? [163, 92] : [214, 124];

			const origin = this.app
				.getService("backend")
				.previewURL(obj, picSize[0], picSize[1]);
			const img = `<img height="${picSize[1]}" width="${
				picSize[0]
			}" src="${origin}" onerror='this.style.display="none"'/>`;

			preview = `<div class="webix_fmanager_card_preview file">${img}</div>`;

			const fileIcon = this.Icon(obj);

			panel = `<div class="webix_fmanager_card_panel file">
		<span class="webix_fmanager_card_name">${fileIcon}<span class="file_name_text">${this.SearchTemplate(
				obj.value
			)}</span></span>${menuIcon}
		</div>`;
		}

		return preview + panel;
	}

	SearchTemplate(name) {
		if (this.State.search) {
			const rex = new RegExp("(" + this.State.search + ")", "gi");
			return name.replace(
				rex,
				"<span class='webix_fmanager_search_mark'>$1</span>"
			);
		}

		return name;
	}
}
