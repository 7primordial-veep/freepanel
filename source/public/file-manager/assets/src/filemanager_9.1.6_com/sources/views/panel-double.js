import { JetView } from "webix-jet";
import { createState } from "jet-restate";

export default class DoublePanelView extends JetView {
	config() {
		const panels = {
			type: "wide",
			cols: [
				{ $subview: true, branch: true, name: "left" },
				{ view: "resizer" },
				{ $subview: true, branch: true, name: "right" },
			],
		};

		return panels;
	}
	init() {
		this.State = this.getParam("state");

		const left = createState({
			selectedItem: [].concat(this.State.selectedItem),
			path: this.State.path,
			source: this.GetSource("left"),
			mode: "list",
			isActive: true,
		});
		left.selectedItem.$noSelect = this.State.selectedItem.$noSelect;

		const right = createState({
			selectedItem: [],
			path: this.State.path,
			source: this.GetSource("right"),
			mode: "list",
			isActive: false,
		});

		this._TrackChanges(left, right);
		this._TrackChanges(right, left);

		this.show("panel/list", {
			target: "left",
			params: { trackActive: true, state: left },
		});
		this.show("panel/list", {
			target: "right",
			params: { trackActive: true, state: right },
		});
	}

	_TrackChanges(state, next) {
		this.on(state.$changes, "path", v => {
			if (state.isActive) this.State.path = v;
		});
		this.on(state.$changes, "source", v => (this.State.source = v));
		this.on(state.$changes, "selectedItem", v => {
			if (state.isActive) this.State.selectedItem = v;
		});
		this.on(state.$changes, "isActive", v => {
			if (v)
				this.State.$batch({
					path: state.path,
					source: state.source,
				});
			next.isActive = !v;
		});
		this.on(this.app, "pathChanged", (opath, npath) => {
			if (
				!state.isActive &&
				(state.path === opath || state.path.indexOf(opath + "/") === 0)
			) {
				state.path = state.path.replace(opath, npath);
			}
		});
	}
	GetSource() {
		return this.State.source;
	}
}
