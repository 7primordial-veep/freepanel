import { safeName } from "helpers/common";

export function prompt(config) {
	const result = new webix.promise.defer();

	const p = webix.ui({
		view: "window",
		css: "webix_fmanager_prompt",
		modal: true,
		move: true,
		head: {
			view: "toolbar",
			padding: { left: 12, right: 4 },
			borderless: true,
			elements: [
				{ view: "label", label: config.text },
				{
					view: "icon",
					icon: "wxi-close",
					hotkey: "esc",
					click: () => {
						result.reject("prompt cancelled");
						p.close();
					},
				},
			],
		},
		position: config.master ? "" : "center",
		body: {
			view: "form",
			padding: { top: 0, left: 12, right: 12, bottom: 12 },
			rows: [
				{
					margin: 10,
					cols: [
						{
							view: "text",
							name: "name",
							value: config.value,
							width: 230,
							validate: safeName,
							css: "webix_fmanager_prompt_input",
						},
						{
							view: "button",
							value: config.button,
							css: "webix_primary",
							width: 100,
							hotkey: "enter",
							click: function() {
								const popup = this.getTopParentView();
								const form = popup.getBody();

								if (form.validate()) {
									const newname = form.getValues().name;
									result.resolve(newname);
									popup.close();
								} else {
									webix.UIManager.setFocus(form);
								}
							},
						},
					],
				},
			],
		},
		on: {
			onShow() {
				const input = this.getBody().elements.name.getInputNode();
				input.focus();
				if (config.selectMask) config.selectMask(input);
				else input.select();
			},
		},
	});

	const position = config.master ? { x: 50 } : null;
	webix.delay(() => p.show(config.master, position));

	return result;
}
