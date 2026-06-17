export default class Progress {
	constructor() {
		this.view = null;
		this.popup = null;
	}

	handle(view, popup) {
		this.view = view;
		webix.extend(view, webix.ProgressBar);

		this.popup = popup;
	}

	start(size) {
		const view = this.view;
		if (!view || view.$destructed) return;

		view.showProgress({
			type: "top",
			delay: 3000,
			hide: true,
			position: size
		});
	}

	end() {
		const view = this.view;
		if (!view || view.$destructed) return;

		view.hideProgress();
	}

	files(head, files, code) {
		if (!files.length) return;
		if (files.length == 1) {
			this.start();
			return code(files[0], 0).finally(() => this.end());
		}

		if (this.popup)
			return this.popup({
				config: { head, files, code },
			}).then(popup => popup.WaitClose);
	}
}
