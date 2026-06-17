import DataViewBase from "views/sections/dataview";
import hotkey from "jet-hotkey";

import { folderIcon, backIcon } from "../helpers/icons";
import { formatTemplate } from "../helpers/common";

export default class GridView extends DataViewBase {
	config() {
		const _ = this.app.getService("locale")._;
		const compact = this.getParam("compact", true);

		const grid = {
			view: "datatable",
			localId: "table",
			css: "webix_noselect webix_header_border webix_fmanager_filelist",
			select: "row",
			multiselect: true,
			drag: true,
			resizeColumn: { headerOnly: true },
			sort: "multi",
			type: {
				backIcon: () => backIcon,
				backLabel: () => _("back to parent folder"),
			},
			on: {
				onItemDblClick: () => this.Activate(this.GetSelection()),
				onEnter: () => this.Activate(this.GetSelection()),
				onBeforeDrag: ctx => this.DragMarker(ctx),
				onBeforeDrop: ctx => this.MoveFiles(ctx.source, ctx.target),
				"data->onStoreLoad": () => {
					// needed for folder content reloads or path change after some sorting
					this.WTable.markSorting();
				},
			},
			onClick: {
				webix_ss_center_scroll: () => this.EmptyClick(),
				webix_column: () => false,
			},
			onContext: {
				webix_ss_center_scroll: (e, id) => {
					if (!id) this.EmptyClick();
				},
			},
			columns: [
				{
					id: "value",
					header: "",
					template: obj => this.NameTemplate(obj),
					sort: sort("value"),
					fillspace: true,
				},
				{
					id: "size",
					header: _("Size"),
					template: obj =>
						obj.type !== "folder" ? formatTemplate(obj.size) : "",
					sort: sort("size"),
				},
				{
					id: "date",
					header: _("Date"),
					sort: sort("date"),
					format: date => {
						if (date instanceof Date && !isNaN(date))
							return webix.i18n.longDateFormatStr(date);
						else return "";
					},
					width: 150,
				},
			],
		};

		if (compact) {
			grid.columns.splice(1, 2);
		}

		return grid;
	}
	init() {
		this.WTable = this.$$("table");
		super.init();

		this.on(this.State.$changes, "isActive", v => {
			if (!v) {
				this.Menu.Hide();
				this._Track = false;
				this.WTable.unselect();
				this._Track = true;
			} else {
				this.SelectActive();
				webix.delay(() => webix.UIManager.setFocus(this.WTable));
			}
		});

		// other hotkeys are shared, this one is specific
		this.on(hotkey(this.getRoot()), "TAB", () => {
			if (this.getParam("trackActive", true)) this.State.isActive = false;
		});
	}
	RenderData(data) {
		this.WTable.sync(data);
	}
	NameTemplate(obj) {
		return obj.type === "folder"
			? folderIcon + obj.value
			: `${this.Icon(obj)}<span class="file-name">${obj.value}</span>`;
	}
}

function sort(by) {
	return function(a, b) {
		if (
			a.value === ".." ||
			b.value === ".." ||
			(a.type === "folder" && b.type !== "folder") ||
			(b.type === "folder" && a.type !== "folder")
		)
			return 0;

		if (by === "value")
			return a.value.localeCompare(b.value, undefined, {
				ignorePunctuation: true,
				numeric: true,
			});

		return a[by] < b[by] ? -1 : a[by] > b[by] ? 1 : 0;
	};
}
