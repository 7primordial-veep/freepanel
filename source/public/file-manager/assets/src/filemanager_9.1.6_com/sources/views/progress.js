import { JetView } from "webix-jet";
import { createState } from "jet-restate";
import { iterateAsync } from "../helpers/common";

export default class ProgressView extends JetView {
	config() {
		this.Config = this.getParam("config");

		return {
			view: "window",
			position: "center",
			modal: true,
			css: "webix_fmanager_progress",
			head: {
				template: this.Config.head,
				css: "webix_fmanager_progress_head",
				height: 54,
			},
			body: {
				padding: { top: 0, bottom: 20, left: 20, right: 20 },
				margin: 10,
				rows: [
					{
						template: obj =>
							`<div class="webix_fmanager_progress_bar">
<div class="webix_fmanager_progress_counter">${obj.done} of ${obj.total} items</div>
<div class="webix_fmanager_progress_name">${obj.file}</div></div>`,
						localId: "counter",
						borderless: true,
						height: 59,
					},
					{
						view: "button",
						value: "Stop",
						css: "webix_fmanager_progress_cancel",
						click: () => {
							this.State.cancel = true;
							this.getRoot().disable();
						},
					},
				],
			},
		};
	}
	ready() {
		this.WaitClose = webix.promise.defer();
		this.Counter = this.$$("counter");

		this.Counter.setValues({
			done: 0,
			total: this.Config.files.length,
			file: this.Config.files[0],
		});
		webix.extend(this.Counter, webix.ProgressBar);

		this.State = createState({ i: 0, cancel: false });
		iterateAsync(this.Config.files, this.Config.code, this.State).finally(() =>
			this.Close()
		);

		this.on(this.State.$changes, "i", i => i && this.Step(i));
	}
	Close() {
		this.Counter.showProgress({ type: "bottom", position: 1, delay: 100 });
		// FIXME - _hidden
		this.show("_blank", { target: "popup" });
		this.WaitClose.resolve();
	}
	Step(i) {
		let done = this.Counter.getValues().done;

		this.Counter.setValues({ done: i, file: this.Config.files[i - 1] }, true);

		done = (done + 1) / this.Config.files.length;
		this.Counter.showProgress({
			type: "bottom",
			position: Math.min(1, done),
			delay: 100,
		});
	}
}
