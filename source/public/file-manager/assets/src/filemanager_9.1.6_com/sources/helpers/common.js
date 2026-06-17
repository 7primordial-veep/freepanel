export function fileNameSelectMask(input) {
	const extIndex = input.value.lastIndexOf(".");
	if (extIndex > -1) input.setSelectionRange(0, extIndex);
	else input.select();
}

export function iterateAsync(arr, code, ctx) {
	ctx = ctx || { i: 0, cancel: false };

	if (ctx.i >= arr.length) return;

	return code(arr[ctx.i], ctx.i).then(() => {
		ctx.i += 1;
		if (!ctx.cancel) return iterateAsync(arr, code, ctx);
	});
}

export function formatTemplate(size) {
	if (size >= 1000000000) return (size / 1000000000).toFixed(1) + " Gb";
	if (size >= 1000000) return (size / 1000000).toFixed(1) + " Mb";
	if (size >= 1000) return (size / 1000).toFixed(1) + " kb";

	return size + " b";
}

export function ext(path) {
	if (!path) return "";
	const parts = path.split(".");
	if (parts.length < 2) return "";
	return parts[parts.length - 1];
}

export function getLastSelected(files) {
	return files.length
		? files[files.length - 1]
		: { type: "empty", value: "Nothing is selected" };
}

export function safeName(str) {
	str = str.replace(/[/\\:*?"<>|]/g, "").trim();
	while (str[0] === ".") str = str.substr(1).trim();

	return str;
}

export function capitalize(str) {
	if (!str) return "";
	return str.charAt(0).toUpperCase() + str.slice(1);
}
