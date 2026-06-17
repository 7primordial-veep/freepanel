const chokidar = require("chokidar");
var uglifycss = require("uglifycss");
var liveServer = require("live-server");
const sass = require("sass");
const fs = require("fs-extra");
const util = require("util");
const glob = require("glob");
const chalk = require("chalk");
const path = require("path");

// rollup
const rollup = require("rollup");
const rollupConfig = require("./rollup.config.js");
const productConfig = obj =>
	rollupConfig({
		banner: "",
		...obj,
		name: "filemanager",
		libName: "fileManager",
	});

// patch callbacks
sass.renderAsync = util.promisify(sass.render);
const globAsync = util.promisify(glob);

// helpers
const debounceTimers = {};
const debounce = (handler, name, time = 100) => {
	clearTimeout(debounceTimers[name]);
	debounceTimers[name] = setTimeout(handler, time);
};

function reportError(err) {
	if (err) {
		console.error(chalk.red(err.formatted || err.toString()));
		return true;
	}
}

async function collectFiles(root, mask, target) {
	const folder = "sources/" + root;
	const files = await globAsync(folder + mask);
	const code1 = [];
	const code2 = [];

	files.map((a, i) => {
		let name = a.replace(folder, "");
		const parts = name.split("/");
		const last = parts[parts.length - 1];
		if (parts.length > 1 && (last === "index.js" || last === "index.ts")) {
			//discard index.js
			parts.pop();
		} else {
			// remove extension
			name = name.substr(0, name.length - 3);
			parts[parts.length - 1] = last.substr(0, last.length - 3);
		}

		code1.push(`import m${i} from "./${root}${name}";`);
		code2.push(`views["${parts.join("/")}"] = m${i};`);
	});

	const codeFull = `${code1.join("\n")}
${root === "views/" ? 'import { JetView } from "webix-jet";\n' : ""}
const views = {${root === "views/" ? " JetView " : ""}};
${code2.join("\n")}

export default views;
`;
	await fs.writeFile("sources/" + target, codeFull);
}

// generate skins
async function skins(list, compress) {
	const start = new Date();

	const processes = list.map(skin =>
		sass.renderAsync({
			file: `./sources/styles/skins/${skin}/_config.scss`,
		})
	);

	const results = await Promise.all(processes);

	for (var i = 0; i < results.length; i++) {
		let outCss = results[i].css.toString();
		let ext = compress ? "min.css" : "css";
		if (compress) outCss = uglifycss.processString(outCss, {});

		await fs.writeFile(
			`./codebase/skins/${list[i]}.${ext}`,
			outCss.replace(/fonts\//g, "../fonts/")
		);
		if (list[i] === "material") {
			await fs.writeFile(`./codebase/filemanager.${ext}`, outCss, reportError);
		}
	}

	console.log(`CSS updated: "${list.join(", ")}"`);
}

async function code(cfg) {
	const start = new Date();

	// build views/langs dictionary
	await collectFiles("views/", "**/*.@(js|ts)", "export_views.js");

	try {
		// create a bundle
		const bundle = await rollup.rollup(cfg);
		// generate code and a sourcemap
		const result = await bundle.generate(cfg.output);
		const names = result.output.map(a => a.fileName);
		// or write the bundle to disk
		await bundle.write(cfg.output);
		console.log(`JS updated: "${names.join(", ")}"`);
	} catch (e) {
		const text =
			e.message +
			(e.loc
				? chalk.white(
						`\n${e.loc.file}    line: ${e.loc.line}, column: ${e.loc.column}`
				  )
				: "") +
			(e.frame ? "\n" + chalk.yellow(e.frame) : "");
		throw new Error(text);
	}
}

async function startServer(port, webix) {
	var params = {
		port: port || 8080,
		host: "0.0.0.0",
		open: true,
		ignore: "sources",
		wait: 0,
		mount: [["/codebase/webix", webix || "./node_modules/@xbs/webix-pro"]],
		logLevel: 0, // 0 = errors only, 1 = some, 2 = lots
		middleware: [
			function(req, res, next) {
				next();
			},
		],
	};
	liveServer.start(params);
}

async function watch(dir) {
	await fs.ensureDir("./codebase/skins");

	// rebuild skins
	chokidar.watch("./sources/styles").on("all", (ev, file) => {
		debounce(async () => {
			try {
				await skins(["material", "mini", "compact", "flat", "contrast"]);
			} catch (e) {
				reportError(e);
			}
		}, "css");
	});

	// rebuild js code
	chokidar.watch("./sources/**/*.(js|ts)").on("all", (ev, file) => {
		if (file === path.join("sources", "export_views.js")) return;

		debounce(async () => {
			try {
				await code(productConfig({ sourcemap: true, dir }));
			} catch (e) {
				reportError(e);
			}
		}, "js");
	});
}

async function files(dir, compress, banner) {
	await fs.ensureDir("./codebase/skins");
	await skins(["material", "mini", "compact", "flat", "contrast"], compress);
	if (compress) {
		await code(
			productConfig({
				transpile: true,
				compress: true,
				sourcemap: true,
				dir,
				banner,
			})
		);
	} else {
		await code(
			productConfig({ transpile: true, dir, sourcemap: false, banner })
		);
	}
}

module.exports = {
	async build(dir, cmd) {
		if (!cmd.preserve) {
			const old = await globAsync(
				path.join(dir, "codebase") + "/**/*.@(js|ts|map)"
			);
			await Promise.all(old.map(f => fs.remove(f)));
		}
		await files(dir, cmd.compress, cmd.banner);
	},
	async server(dir, cmd) {
		await watch(dir);
		await startServer(cmd.port, cmd.webix);
	},
};
