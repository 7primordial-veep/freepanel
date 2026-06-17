import { JetView } from "webix-jet";
import {
	formatTemplate,
	getLastSelected,
	capitalize,
} from "../../helpers/common";

export default class InfoPreview extends JetView {
	config() {
		const _ = this.app.getService("locale")._;

		const dateTimeFormat = webix.Date.dateToStr(
			"%M %j, %Y&nbsp;&nbsp;&nbsp;&nbsp;%H:%i:%s"
		);

		const mainInfo = {
			css: "webix_fmanager_preview_info",
			localId: "info",
			borderless: true,
			autoheight: true,
			template: obj => {
				if (!obj.id) return "";
				const _ = this.app.getService("locale")._;
				const keys = (obj.type === "folder"
					? ["Type", "Date"]
					: ["Type", "Size", "Date"]
				).map(k => `<div class='key_value_cell''>${_(k)}</div>`);

				const keyCol = `<div class='key_col'>${keys.join("")}</div>`;

				const values = (obj.type === "folder"
					? [capitalize(_(obj.type)), dateTimeFormat(obj.date)]
					: [
							capitalize(_(obj.type)),
							formatTemplate(obj.size),
							dateTimeFormat(obj.date),
					  ]
				).map(v => `<div class='key_value_cell'>${v}</div>`);

				const valueCol = `<div class="value_col">${values.join("")}</div>`;

				return `<div>${keyCol}${valueCol}</div>`;
			},
		};

		const extraInfo = {
			css: "webix_fmanager_preview_info extra",
			localId: "extra:info",
			hidden: true,
			autoheight: true,
			template: obj => {
				const tags = Object.keys(obj);
				let keyCol = "<div class='key_col'>";
				for (let i = 0; i < tags.length; ++i) {
					keyCol += `<div class='key_value_cell key'>${tags[i]}</div>`;
				}
				keyCol += "</div>";

				let valueCol = "<div class='value_col'>";
				for (let val in obj) {
					let value = obj[val].trim();
					valueCol += `<div class='key_value_cell'>${
						value && value != "0"
							? value
							: "<span class='webix_fmanager_id3tags-unknown'>Unknown</span>"
					}</div>`;
				}
				valueCol += "</div>";

				return `<div><div class="webix_fmanager_info_header">
<span class="webix_fmanager_icon fmi-information-outline"></span>
<span class="webix_fmanager_info_title">Extra info</span>
</div>${keyCol}${valueCol}</div>`;
			},
		};

		const counter = {
			localId: "search:counter",
			css: "webix_fmanager_preview_info",
			height: 104,
			borderless: true,
			template: o => {
				const keys = ["Folders", "Files"].map(
					k => `<div class='key_value_cell''>${_(k)}</div>`
				);
				const keyCol = `<div class='key_col search'>${keys.join("")}</div>`;

				const values = [o.folders, o.files].map(
					v => `<div class='key_value_cell'>${v}</div>`
				);

				const valueCol = `<div class="value_col search">${values.join(
					""
				)}</div>`;

				return `<div>${keyCol}${valueCol}</div>`;
			},
		};

		const infoTabs = {
			localId: "info:tabs",
			view: "tabview",
			css: "webix_fmanager_info_tab",
			cells: [
				{
					header: _("Information"),
					body: {
						padding: 14,
						margin: 14,
						rows: [mainInfo, extraInfo, {}],
					},
				},
				{
					header: _("Search results"),
					body: {
						padding: 14,
						margin: 14,
						rows: [counter, {}],
					},
				},
			],
		};
		return infoTabs;
	}
	init() {
		this.Tabview = this.$$("info:tabs");
		this.State = this.getParam("state");

		this.on(this.State.$changes, "selectedItem", v => {
			this.ShowInfo(getLastSelected(v));
		});

		this.on(this.State.$changes, "searchStats", v => {
			if (v) {
				this.$$("search:counter").setValues(v);
			} else {
				if (!this.State.selectedItem.length) this.Tabview.hide();
			}
		});
	}
	ShowInfo(v) {
		const tabbar = this.Tabview.getTabbar();
		const infoId = tabbar.config.options[0].id;
		const counterId = tabbar.config.options[1].id;

		if (v.type !== "empty") {
			this.SetInfo(this.$$("info"), v);
			this.SwitchTabs(tabbar, counterId, infoId);

			const extraInfo = this.$$("extra:info");
			const meta = this.app.getService("backend").getMeta(v);
			// if feature is disabled getMeta returns false
			if (!meta) extraInfo.hide();
			else
				meta
					.then(data => this.SetExtraInfo(extraInfo, data))
					.catch(() => extraInfo.hide());
		} else {
			if (!this.State.search) this.Tabview.hide();
			else this.SwitchTabs(tabbar, infoId, counterId);
		}
	}
	SetExtraInfo(view, data) {
		if (!_isEmpty(data)) {
			this.SetInfo(view, data);
		} else view.hide();
	}
	SetInfo(view, data) {
		view.setValues(data);
		view.show();
	}
	SwitchTabs(tab, hide, show) {
		tab.hideOption(hide);
		tab.setValue(show);
		tab.showOption(show);
		this.Tabview.show();
	}
}

// if all tags empty or obj has no keys, do not show them at all
function _isEmpty(obj) {
	for (let i in obj) {
		if (typeof obj[i] === "object") return _isEmpty(obj[i]);
		else if (obj[i] && obj[i] !== "0") return false;
	}
	return true;
}
