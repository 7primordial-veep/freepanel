import { JetView } from "webix-jet";

export default class PanelView extends JetView {
	config() {
		const path = {
			view: "template",
			localId: "path",
			type: "header",
			borderless: true,
			css: "webix_fmanager_path",
			template: obj => this.RenderPath(obj),
			onClick: {
				webix_fmanager_path_chunk: e => this.ChangePath(e),
			},
		};

		const refresh = {
			view: "icon",
			icon: "wxi-sync",
			css: "webix_fmanager_spec_icon",
			tooltip: "Refresh",
			click: () => {
				this.app.getService("local").refresh(this.State.path);
			},
		};

		const toolbar = {
			view: "toolbar",
			paddingX: 4,
			cols: [refresh, path],
		};

		return {
			rows: [
				toolbar,
				{ $subview: true, params: { state: this.getParam("state") } },
			],
		};
	}
	init() {
		this.State = this.getParam("state");

		this.on(this.State.$changes, "path", v => this.ProcessPath(v));

		const uapi = this.app.getService("upload").getUploader();
		uapi.addDropZone(this.getRoot().$view);
		this.on(uapi, "onBeforeFileDrop", (files, e) => {
			if (this.getRoot().$view.contains(e.target))
				uapi.config.tempUrlData = { id: this.State.path };
		});
	}

	ProcessPath(v) {
		this.app
			.getService("local")
			.folders()
			.then(dirs => {
				let path = ["/"];
				if (v !== "/") {
					const p = v.split("/");
					for (let i = 1, id = ""; i < p.length; ++i) {
						id += `/${p[i]}`;
						path.push(dirs.getItem(id).value);
					}
				}
				this.$$("path").setValues({ path: path });
			});
	}

	RenderPath(obj) {
		if (obj.path && obj.path.length) {
			const icon = "<span class='webix_icon wxi-angle-right'></span>";
			const rootName = this.app.getService("backend").getRootName();

			// template data is initially undefined
			let htmlPath = "";
			obj.path.forEach((chunk, index) => {
				// as folders may have the same name, index is stored as "data-path" attribute
				htmlPath += `<span class="webix_fmanager_path_chunk" data-path="${index}">${
					index ? chunk : rootName
				}</span>`;
				if (index < obj.path.length - 1) htmlPath += icon;
			});

			return htmlPath;
		}
		return "";
	}

	ChangePath(e) {
		const chunkInd = e.target.getAttribute("data-path") * 1;
		const path = this.State.path.split("/");
		path.splice(chunkInd + 1, path.length - 1);
		const newPath = path.join("/") || "/";
		this.State.path = newPath;
	}
}
