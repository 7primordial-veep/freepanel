/* global require, module */
const typescript = require("@rollup/plugin-typescript");
const replace = require("@rollup/plugin-replace");
const terser = require("rollup-plugin-terser").terser;
const path = require("path");

module.exports = cfg => {
	const pkg = require(path.join(cfg.dir, "package.json"));
	const out = {
		input: "sources/app.js",
		output: {
			file: `codebase/${cfg.name}.js`,
			name: cfg.libName,
			format: "umd",
			sourcemap: true,
			banner: cfg.banner,
		},
		plugins: [
			replace({
				include: ["./sources/app.js"],
				values: {
					VERSION: JSON.stringify(pkg.version),
					DEBUG: JSON.stringify(true),
				},
			}),
			typescript(cfg.transpile ? { include: "**/*.{ts,js}" } : {}),
		],
	};

	if (cfg.compress) {
		out.output.file = `codebase/${cfg.name}.min.js`;
		out.output.plugins = [terser()];
	}

	out.output.sourcemap = cfg.sourcemap;

	return out;
};
