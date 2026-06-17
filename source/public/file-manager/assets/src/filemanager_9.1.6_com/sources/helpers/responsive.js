webix.protoUI(
	{
		name: "r-layout",
		sizeTrigger(width, handler, value) {
			this._compactValue = value;
			this._compactWidth = width;
			this._compactHandler = handler;

			this._checkTrigger(this.$view.width, value);
		},
		_checkTrigger(x, value) {
			if (this._compactWidth) {
				if (
					(x <= this._compactWidth && !value) ||
					(x > this._compactWidth && value)
				) {
					this._compactWidth = null;
					this._compactHandler(!value);
					return false;
				}
			}
			return true;
		},
		$setSize(x, y) {
			if (this._checkTrigger(x, this._compactValue))
				return webix.ui.layout.prototype.$setSize.call(this, x, y);
		},
	},
	webix.ui.layout
);
