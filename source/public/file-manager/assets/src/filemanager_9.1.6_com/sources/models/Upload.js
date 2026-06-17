export default class UploadHandler {
	constructor(app, state) {
		this.initUploader(app);
		this.initEvents(app, state);
	}

	initEvents(app, state) {
		app.attachEvent("app:action", (name, info) => {
			if (name == "upload") {
				info = info || (state.path || "/");
				app.getService("upload").fileDialog(info);
			}
		});
	}

	initUploader(app) {
		this.uploader = webix.ui({
			view: "uploader",
			apiOnly: true,
			upload: app.getService("backend").upload(),
			on: {
				onAfterFileAdd: function(item) {
					item.urlData = this.config.tempUrlData;
				},
				onFileUpload: (file, res) => {
					app.getService("local").addFile(file.urlData.id, res);
				},
				onUploadComplete: () => {
					app.getService("progress").end();
					app.callEvent("reload:fs:stats", []);
				},
			},
		});

		this.uploader.$updateProgress = function(_, percent) {
			const progress = percent / 100;

			if (progress) app.getService("progress").start(progress);
		};
	}

	getUploader() {
		return this.uploader;
	}

	fileDialog(id) {
		this.uploader.config.tempUrlData = { id };
		this.uploader.fileDialog();
	}
}
