import { JetApp, plugins, EmptyRouter } from "webix-jet";
import { createState, link } from "jet-restate";

import views from "./export_views";
import en from "locales/en";

import Backend from "./models/Backend";
import LocalData from "./models/LocalData";
import Upload from "./models/Upload";
import Progress from "./models/Progress";
import Operations from "./models/Operations";

export class App extends JetApp {
	constructor(config) {
		const state = createState({
			mode: config.mode || "grid",
			selectedItem: [],
			search: "",
			searchStats: null,
			path: config.path || "/",
			source: config.source || "files",
			clipboard: null,
		});

		const defaults = {
			router: EmptyRouter,
			version: VERSION,
			debug: DEBUG,
			start: "/top",
			params: { state, forceCompact: config.compact },
			editor: true,
			player: true,
			compactWidth: 640,
		};

		super({ ...defaults, ...config });

		this.setService(
			"backend",
			new (this.dynamic(Backend))(this, this.config.url)
		);
		this.setService("local", new (this.dynamic(LocalData))(this, 10));
		this.setService("progress", new (this.dynamic(Progress))(this));
		this.setService("upload", new (this.dynamic(Upload))(this, state));
		this.setService("operations", new (this.dynamic(Operations))(this, state));

		this.use(
			plugins.Locale,
			this.config.locale || {
				lang: "en",
				webix: {
					en: "en-US",
					zh: "zh-CN",
				},
			}
		);
	}

	dynamic(obj) {
		return this.config.override ? this.config.override.get(obj) || obj : obj;
	}

	require(type, name) {
		if (type === "jet-views") return views[name];
		else if (type === "jet-locales") return locales[name];

		return null;
	}

	// for external users
	getState() {
		return this.config.params.state;
	}
}

webix.protoUI(
	{
		name: "filemanager",
		app: App,
		getState() {
			return this.$app.getState();
		},
		getService(name) {
			return this.$app.getService(name);
		},
		$init() {
			const state = this.$app.getState();
			for (let key in state) {
				link(state, this.config, key);
			}
		},
	},
	webix.ui.jetapp
);

// re-export for customization
const services = { Backend, LocalData, Upload, Progress, Operations };
const locales = { en };
export { views, locales, services };
