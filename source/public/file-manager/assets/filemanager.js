/*
@license
Webix FileManager v.9.1.6
This software is covered by Webix Commercial License.
Usage without proper license is prohibited.
(c) XB Software Ltd.
*/
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
    typeof define === 'function' && define.amd ? define(['exports'], factory) :
    (global = global || self, factory(global.fileManager = {}));
}(this, (function (exports) { 'use strict';

    /*! *****************************************************************************
    Copyright (c) Microsoft Corporation. All rights reserved.
    Licensed under the Apache License, Version 2.0 (the "License"); you may not use
    this file except in compliance with the License. You may obtain a copy of the
    License at http://www.apache.org/licenses/LICENSE-2.0

    THIS CODE IS PROVIDED ON AN *AS IS* BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
    KIND, EITHER EXPRESS OR IMPLIED, INCLUDING WITHOUT LIMITATION ANY IMPLIED
    WARRANTIES OR CONDITIONS OF TITLE, FITNESS FOR A PARTICULAR PURPOSE,
    MERCHANTABLITY OR NON-INFRINGEMENT.

    See the Apache Version 2.0 License for specific language governing permissions
    and limitations under the License.
    ***************************************************************************** */
    /* global Reflect, Promise */

    var extendStatics = function(d, b) {
        extendStatics = Object.setPrototypeOf ||
            ({ __proto__: [] } instanceof Array && function (d, b) { d.__proto__ = b; }) ||
            function (d, b) { for (var p in b) if (b.hasOwnProperty(p)) d[p] = b[p]; };
        return extendStatics(d, b);
    };

    function __extends(d, b) {
        extendStatics(d, b);
        function __() { this.constructor = d; }
        d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
    }

    var __assign = function() {
        __assign = Object.assign || function __assign(t) {
            for (var s, i = 1, n = arguments.length; i < n; i++) {
                s = arguments[i];
                for (var p in s) if (Object.prototype.hasOwnProperty.call(s, p)) t[p] = s[p];
            }
            return t;
        };
        return __assign.apply(this, arguments);
    };

    var NavigationBlocked = (function () {
        function NavigationBlocked() {
        }
        return NavigationBlocked;
    }());
    var JetBase = (function () {
        function JetBase(webix, config) {
            this.webixJet = true;
            this.webix = webix;
            this._events = [];
            this._subs = {};
            this._data = {};
            if (config && config.params)
                webix.extend(this._data, config.params);
        }
        JetBase.prototype.getRoot = function () {
            return this._root;
        };
        JetBase.prototype.destructor = function () {
            this._detachEvents();
            this._destroySubs();
            this._events = this._container = this.app = this._parent = this._root = null;
        };
        JetBase.prototype.setParam = function (id, value, url) {
            if (this._data[id] !== value) {
                this._data[id] = value;
                this._segment.update(id, value, 0);
                if (url) {
                    return this.show(null);
                }
            }
        };
        JetBase.prototype.getParam = function (id, parent) {
            var value = this._data[id];
            if (typeof value !== "undefined" || !parent) {
                return value;
            }
            var view = this.getParentView();
            if (view) {
                return view.getParam(id, parent);
            }
        };
        JetBase.prototype.getUrl = function () {
            return this._segment.suburl();
        };
        JetBase.prototype.getUrlString = function () {
            return this._segment.toString();
        };
        JetBase.prototype.getParentView = function () {
            return this._parent;
        };
        JetBase.prototype.$$ = function (id) {
            if (typeof id === "string") {
                var root_1 = this.getRoot();
                return root_1.queryView((function (obj) { return (obj.config.id === id || obj.config.localId === id) &&
                    (obj.$scope === root_1.$scope); }), "self");
            }
            else {
                return id;
            }
        };
        JetBase.prototype.on = function (obj, name, code) {
            var id = obj.attachEvent(name, code);
            this._events.push({ obj: obj, id: id });
            return id;
        };
        JetBase.prototype.contains = function (view) {
            for (var key in this._subs) {
                var kid = this._subs[key].view;
                if (kid === view || kid.contains(view)) {
                    return true;
                }
            }
            return false;
        };
        JetBase.prototype.getSubView = function (name) {
            var sub = this.getSubViewInfo(name);
            if (sub) {
                return sub.subview.view;
            }
        };
        JetBase.prototype.getSubViewInfo = function (name) {
            var sub = this._subs[name || "default"];
            if (sub) {
                return { subview: sub, parent: this };
            }
            if (name === "_top") {
                this._subs[name] = { url: "", id: null, popup: true };
                return this.getSubViewInfo(name);
            }
            if (this._parent) {
                return this._parent.getSubViewInfo(name);
            }
            return null;
        };
        JetBase.prototype._detachEvents = function () {
            var events = this._events;
            for (var i = events.length - 1; i >= 0; i--) {
                events[i].obj.detachEvent(events[i].id);
            }
        };
        JetBase.prototype._destroySubs = function () {
            for (var key in this._subs) {
                var subView = this._subs[key].view;
                if (subView) {
                    subView.destructor();
                }
            }
            this._subs = {};
        };
        JetBase.prototype._init_url_data = function () {
            var url = this._segment.current();
            this._data = {};
            this.webix.extend(this._data, url.params, true);
        };
        JetBase.prototype._getDefaultSub = function () {
            if (this._subs.default) {
                return this._subs.default;
            }
            for (var key in this._subs) {
                var sub = this._subs[key];
                if (!sub.branch && sub.view && key !== "_top") {
                    var child = sub.view._getDefaultSub();
                    if (child) {
                        return child;
                    }
                }
            }
        };
        JetBase.prototype._routed_view = function () {
            var parent = this.getParentView();
            if (!parent) {
                return true;
            }
            var sub = parent._getDefaultSub();
            if (!sub && sub !== this) {
                return false;
            }
            return parent._routed_view();
        };
        return JetBase;
    }());
    function parse(url) {
        if (url[0] === "/") {
            url = url.substr(1);
        }
        var parts = url.split("/");
        var chunks = [];
        for (var i = 0; i < parts.length; i++) {
            var test = parts[i];
            var result = {};
            var pos = test.indexOf(":");
            if (pos === -1) {
                pos = test.indexOf("?");
            }
            if (pos !== -1) {
                var params = test.substr(pos + 1).split(/[\:\?\&]/g);
                for (var _i = 0, params_1 = params; _i < params_1.length; _i++) {
                    var param = params_1[_i];
                    var dchunk = param.split("=");
                    result[dchunk[0]] = decodeURIComponent(dchunk[1]);
                }
            }
            chunks[i] = {
                page: (pos > -1 ? test.substr(0, pos) : test),
                params: result,
                isNew: true
            };
        }
        return chunks;
    }
    function url2str(stack) {
        var url = [];
        for (var _i = 0, stack_1 = stack; _i < stack_1.length; _i++) {
            var chunk = stack_1[_i];
            url.push("/" + chunk.page);
            var params = obj2str(chunk.params);
            if (params) {
                url.push("?" + params);
            }
        }
        return url.join("");
    }
    function obj2str(obj) {
        var str = [];
        for (var key in obj) {
            if (typeof obj[key] === "object")
                continue;
            if (str.length) {
                str.push("&");
            }
            str.push(key + "=" + encodeURIComponent(obj[key]));
        }
        return str.join("");
    }
    var Route = (function () {
        function Route(route, index) {
            this._next = 1;
            if (typeof route === "string") {
                this.route = {
                    url: parse(route),
                    path: route
                };
            }
            else {
                this.route = route;
            }
            this.index = index;
        }
        Route.prototype.current = function () {
            return this.route.url[this.index];
        };
        Route.prototype.next = function () {
            return this.route.url[this.index + this._next];
        };
        Route.prototype.suburl = function () {
            return this.route.url.slice(this.index);
        };
        Route.prototype.shift = function (params) {
            var route = new Route(this.route, this.index + this._next);
            route.setParams(route.route.url, params, route.index);
            return route;
        };
        Route.prototype.setParams = function (url, params, index) {
            if (params) {
                var old = url[index].params;
                for (var key in params)
                    old[key] = params[key];
            }
        };
        Route.prototype.refresh = function () {
            var url = this.route.url;
            for (var i = this.index + 1; i < url.length; i++) {
                url[i].isNew = true;
            }
        };
        Route.prototype.toString = function () {
            var str = url2str(this.suburl());
            return str ? str.substr(1) : "";
        };
        Route.prototype._join = function (path, kids) {
            var url = this.route.url;
            if (path === null) {
                return url;
            }
            var old = this.route.url;
            var reset = true;
            url = old.slice(0, this.index + (kids ? this._next : 0));
            if (path) {
                url = url.concat(parse(path));
                for (var i = 0; i < url.length; i++) {
                    if (old[i]) {
                        url[i].view = old[i].view;
                    }
                    if (reset && old[i] && url[i].page === old[i].page) {
                        url[i].isNew = false;
                    }
                    else if (url[i].isNew) {
                        reset = false;
                    }
                }
            }
            return url;
        };
        Route.prototype.append = function (path) {
            var url = this._join(path, true);
            this.route.path = url2str(url);
            this.route.url = url;
            return this.route.path;
        };
        Route.prototype.show = function (path, view, kids) {
            var _this = this;
            var url = this._join(path.url, kids);
            this.setParams(url, path.params, this.index + (kids ? this._next : 0));
            return new Promise(function (res, rej) {
                var redirect = url2str(url);
                var obj = {
                    url: url,
                    redirect: redirect,
                    confirm: Promise.resolve()
                };
                var app = view ? view.app : null;
                if (app) {
                    var result = app.callEvent("app:guard", [obj.redirect, view, obj]);
                    if (!result) {
                        rej(new NavigationBlocked());
                        return;
                    }
                }
                obj.confirm.catch(function (err) { return rej(err); }).then(function () {
                    if (obj.redirect === null) {
                        rej(new NavigationBlocked());
                        return;
                    }
                    if (obj.redirect !== redirect) {
                        app.show(obj.redirect);
                        rej(new NavigationBlocked());
                        return;
                    }
                    _this.route.path = redirect;
                    _this.route.url = url;
                    res();
                });
            });
        };
        Route.prototype.size = function (n) {
            this._next = n;
        };
        Route.prototype.split = function () {
            var route = {
                url: this.route.url.slice(this.index + 1),
                path: ""
            };
            if (route.url.length) {
                route.path = url2str(route.url);
            }
            return new Route(route, 0);
        };
        Route.prototype.update = function (name, value, index) {
            var chunk = this.route.url[this.index + (index || 0)];
            if (!chunk) {
                this.route.url.push({ page: "", params: {} });
                return this.update(name, value, index);
            }
            if (name === "") {
                chunk.page = value;
            }
            else {
                chunk.params[name] = value;
            }
            this.route.path = url2str(this.route.url);
        };
        return Route;
    }());
    var JetView = (function (_super) {
        __extends(JetView, _super);
        function JetView(app, config) {
            var _this = _super.call(this, app.webix) || this;
            _this.app = app;
            _this._children = [];
            return _this;
        }
        JetView.prototype.ui = function (ui, config) {
            config = config || {};
            var container = config.container || ui.container;
            var jetview = this.app.createView(ui);
            this._children.push(jetview);
            jetview.render(container, this._segment, this);
            if (typeof ui !== "object" || (ui instanceof JetBase)) {
                return jetview;
            }
            else {
                return jetview.getRoot();
            }
        };
        JetView.prototype.show = function (path, config) {
            config = config || {};
            if (typeof path === "object") {
                for (var key in path) {
                    this.setParam(key, path[key]);
                }
                path = null;
            }
            else {
                if (path.substr(0, 1) === "/") {
                    return this.app.show(path, config);
                }
                if (path.indexOf("./") === 0) {
                    path = path.substr(2);
                }
                if (path.indexOf("../") === 0) {
                    var parent_1 = this.getParentView();
                    if (parent_1) {
                        return parent_1.show(path.substr(3), config);
                    }
                    else {
                        return this.app.show("/" + path.substr(3));
                    }
                }
                var sub = this.getSubViewInfo(config.target);
                if (sub) {
                    if (sub.parent !== this) {
                        return sub.parent.show(path, config);
                    }
                    else if (config.target && config.target !== "default") {
                        return this._renderFrameLock(config.target, sub.subview, {
                            url: path,
                            params: config.params,
                        });
                    }
                }
                else {
                    if (path) {
                        return this.app.show("/" + path, config);
                    }
                }
            }
            return this._show(this._segment, { url: path, params: config.params }, this);
        };
        JetView.prototype._show = function (segment, path, view) {
            var _this = this;
            return segment.show(path, view, true).then(function () {
                _this._init_url_data();
                return _this._urlChange();
            }).then(function () {
                if (segment.route.linkRouter) {
                    _this.app.getRouter().set(segment.route.path, { silent: true });
                    _this.app.callEvent("app:route", [segment.route.path]);
                }
            });
        };
        JetView.prototype.init = function (_$view, _$) {
        };
        JetView.prototype.ready = function (_$view, _$url) {
        };
        JetView.prototype.config = function () {
            this.app.webix.message("View:Config is not implemented");
        };
        JetView.prototype.urlChange = function (_$view, _$url) {
        };
        JetView.prototype.destroy = function () {
        };
        JetView.prototype.destructor = function () {
            this.destroy();
            this._destroyKids();
            if (this._root) {
                this._root.destructor();
                _super.prototype.destructor.call(this);
            }
        };
        JetView.prototype.use = function (plugin, config) {
            plugin(this.app, this, config);
        };
        JetView.prototype.refresh = function () {
            var url = this.getUrl();
            this.destroy();
            this._destroyKids();
            this._destroySubs();
            this._detachEvents();
            if (this._container.tagName) {
                this._root.destructor();
            }
            this._segment.refresh();
            return this._render(this._segment);
        };
        JetView.prototype.render = function (root, url, parent) {
            var _this = this;
            if (typeof url === "string") {
                url = new Route(url, 0);
            }
            this._segment = url;
            this._parent = parent;
            this._init_url_data();
            root = root || document.body;
            var _container = (typeof root === "string") ? this.webix.toNode(root) : root;
            if (this._container !== _container) {
                this._container = _container;
                return this._render(url);
            }
            else {
                return this._urlChange().then(function () { return _this.getRoot(); });
            }
        };
        JetView.prototype._render = function (url) {
            var _this = this;
            var config = this.config();
            if (config.then) {
                return config.then(function (cfg) { return _this._render_final(cfg, url); });
            }
            else {
                return this._render_final(config, url);
            }
        };
        JetView.prototype._render_final = function (config, url) {
            var _this = this;
            var slot = null;
            var container = null;
            var show = false;
            if (!this._container.tagName) {
                slot = this._container;
                if (slot.popup) {
                    container = document.body;
                    show = true;
                }
                else {
                    container = this.webix.$$(slot.id);
                }
            }
            else {
                container = this._container;
            }
            if (!this.app || !container) {
                return Promise.reject(null);
            }
            var response;
            var current = this._segment.current();
            var result = { ui: {} };
            this.app.copyConfig(config, result.ui, this._subs);
            this.app.callEvent("app:render", [this, url, result]);
            result.ui.$scope = this;
            if (!slot && current.isNew && current.view) {
                current.view.destructor();
            }
            try {
                if (slot && !show) {
                    var oldui = container;
                    var parent_2 = oldui.getParentView();
                    if (parent_2 && parent_2.name === "multiview" && !result.ui.id) {
                        result.ui.id = oldui.config.id;
                    }
                }
                this._root = this.app.webix.ui(result.ui, container);
                var asWin = this._root;
                if (show && asWin.setPosition && !asWin.isVisible()) {
                    asWin.show();
                }
                if (slot) {
                    if (slot.view && slot.view !== this && slot.view !== this.app) {
                        slot.view.destructor();
                    }
                    slot.id = this._root.config.id;
                    if (this.getParentView() || !this.app.app)
                        slot.view = this;
                    else {
                        slot.view = this.app;
                    }
                }
                if (current.isNew) {
                    current.view = this;
                    current.isNew = false;
                }
                response = Promise.resolve(this._init(this._root, url)).then(function () {
                    return _this._urlChange().then(function () {
                        _this._initUrl = null;
                        return _this.ready(_this._root, url.suburl());
                    });
                });
            }
            catch (e) {
                response = Promise.reject(e);
            }
            return response.catch(function (err) { return _this._initError(_this, err); });
        };
        JetView.prototype._init = function (view, url) {
            return this.init(view, url.suburl());
        };
        JetView.prototype._urlChange = function () {
            var _this = this;
            this.app.callEvent("app:urlchange", [this, this._segment]);
            var waits = [];
            for (var key in this._subs) {
                var frame = this._subs[key];
                var wait = this._renderFrameLock(key, frame, null);
                if (wait) {
                    waits.push(wait);
                }
            }
            return Promise.all(waits).then(function () {
                return _this.urlChange(_this._root, _this._segment.suburl());
            });
        };
        JetView.prototype._renderFrameLock = function (key, frame, path) {
            if (!frame.lock) {
                var lock = this._renderFrame(key, frame, path);
                if (lock) {
                    frame.lock = lock.then(function () { return frame.lock = null; }, function () { return frame.lock = null; });
                }
            }
            return frame.lock;
        };
        JetView.prototype._renderFrame = function (key, frame, path) {
            var _this = this;
            if (key === "default") {
                if (this._segment.next()) {
                    var params = path ? path.params : null;
                    if (frame.params) {
                        params = this.webix.extend(params || {}, frame.params);
                    }
                    return this._createSubView(frame, this._segment.shift(params));
                }
                else if (frame.view && frame.popup) {
                    frame.view.destructor();
                    frame.view = null;
                }
            }
            if (path !== null) {
                frame.url = path.url;
                if (frame.params) {
                    path.params = this.webix.extend(path.params || {}, frame.params);
                }
            }
            if (frame.route) {
                if (path !== null) {
                    return frame.route.show(path, frame.view).then(function () {
                        return _this._createSubView(frame, frame.route);
                    });
                }
                if (frame.branch) {
                    return;
                }
            }
            var view = frame.view;
            if (!view && frame.url) {
                if (typeof frame.url === "string") {
                    frame.route = new Route(frame.url, 0);
                    if (path)
                        frame.route.setParams(frame.route.route.url, path.params, 0);
                    if (frame.params)
                        frame.route.setParams(frame.route.route.url, frame.params, 0);
                    return this._createSubView(frame, frame.route);
                }
                else {
                    if (typeof frame.url === "function" && !(view instanceof frame.url)) {
                        view = new (this.app._override(frame.url))(this.app, "");
                    }
                    if (!view) {
                        view = frame.url;
                    }
                }
            }
            if (view) {
                return view.render(frame, (frame.route || this._segment), this);
            }
        };
        JetView.prototype._initError = function (view, err) {
            if (this.app) {
                this.app.error("app:error:initview", [err, view]);
            }
            return true;
        };
        JetView.prototype._createSubView = function (sub, suburl) {
            var _this = this;
            return this.app.createFromURL(suburl.current()).then(function (view) {
                return view.render(sub, suburl, _this);
            });
        };
        JetView.prototype._destroyKids = function () {
            var uis = this._children;
            for (var i = uis.length - 1; i >= 0; i--) {
                if (uis[i] && uis[i].destructor) {
                    uis[i].destructor();
                }
            }
            this._children = [];
        };
        return JetView;
    }(JetBase));
    var JetViewRaw = (function (_super) {
        __extends(JetViewRaw, _super);
        function JetViewRaw(app, config) {
            var _this = _super.call(this, app, config) || this;
            _this._ui = config.ui;
            return _this;
        }
        JetViewRaw.prototype.config = function () {
            return this._ui;
        };
        return JetViewRaw;
    }(JetView));
    var SubRouter = (function () {
        function SubRouter(cb, config, app) {
            this.path = "";
            this.app = app;
        }
        SubRouter.prototype.set = function (path, config) {
            this.path = path;
            var a = this.app;
            a.app.getRouter().set(a._segment.append(this.path), { silent: true });
        };
        SubRouter.prototype.get = function () {
            return this.path;
        };
        return SubRouter;
    }());
    var _once = true;
    var JetAppBase = (function (_super) {
        __extends(JetAppBase, _super);
        function JetAppBase(config) {
            var _this = this;
            var webix = (config || {}).webix || window.webix;
            config = webix.extend({
                name: "App",
                version: "1.0",
                start: "/home"
            }, config, true);
            _this = _super.call(this, webix, config) || this;
            _this.config = config;
            _this.app = _this.config.app;
            _this.ready = Promise.resolve();
            _this._services = {};
            _this.webix.extend(_this, _this.webix.EventSystem);
            return _this;
        }
        JetAppBase.prototype.getUrl = function () {
            return this._subSegment.suburl();
        };
        JetAppBase.prototype.getUrlString = function () {
            return this._subSegment.toString();
        };
        JetAppBase.prototype.getService = function (name) {
            var obj = this._services[name];
            if (typeof obj === "function") {
                obj = this._services[name] = obj(this);
            }
            return obj;
        };
        JetAppBase.prototype.setService = function (name, handler) {
            this._services[name] = handler;
        };
        JetAppBase.prototype.destructor = function () {
            this.getSubView().destructor();
            _super.prototype.destructor.call(this);
        };
        JetAppBase.prototype.copyConfig = function (obj, target, config) {
            if (obj instanceof JetBase ||
                (typeof obj === "function" && obj.prototype instanceof JetBase)) {
                obj = { $subview: obj };
            }
            if (typeof obj.$subview != "undefined") {
                return this.addSubView(obj, target, config);
            }
            var isArray = obj instanceof Array;
            target = target || (isArray ? [] : {});
            for (var method in obj) {
                var point = obj[method];
                if (typeof point === "function" && point.prototype instanceof JetBase) {
                    point = { $subview: point };
                }
                if (point && typeof point === "object" &&
                    !(point instanceof this.webix.DataCollection) && !(point instanceof RegExp) && !(point instanceof Map)) {
                    if (point instanceof Date) {
                        target[method] = new Date(point);
                    }
                    else {
                        var copy = this.copyConfig(point, (point instanceof Array ? [] : {}), config);
                        if (copy !== null) {
                            if (isArray)
                                target.push(copy);
                            else
                                target[method] = copy;
                        }
                    }
                }
                else {
                    target[method] = point;
                }
            }
            return target;
        };
        JetAppBase.prototype.getRouter = function () {
            return this.$router;
        };
        JetAppBase.prototype.clickHandler = function (e, target) {
            if (e) {
                target = target || (e.target || e.srcElement);
                if (target && target.getAttribute) {
                    var trigger_1 = target.getAttribute("trigger");
                    if (trigger_1) {
                        this._forView(target, function (view) { return view.app.trigger(trigger_1); });
                        e.cancelBubble = true;
                        return e.preventDefault();
                    }
                    var route_1 = target.getAttribute("route");
                    if (route_1) {
                        this._forView(target, function (view) { return view.show(route_1); });
                        e.cancelBubble = true;
                        return e.preventDefault();
                    }
                }
            }
            var parent = target.parentNode;
            if (parent) {
                this.clickHandler(e, parent);
            }
        };
        JetAppBase.prototype.getRoot = function () {
            return this.getSubView().getRoot();
        };
        JetAppBase.prototype.refresh = function () {
            var _this = this;
            if (!this._subSegment) {
                return Promise.resolve(null);
            }
            return this.getSubView().refresh().then(function (view) {
                _this.callEvent("app:route", [_this.getUrl()]);
                return view;
            });
        };
        JetAppBase.prototype.loadView = function (url) {
            var _this = this;
            var views = this.config.views;
            var result = null;
            if (url === "") {
                return Promise.resolve(this._loadError("", new Error("Webix Jet: Empty url segment")));
            }
            try {
                if (views) {
                    if (typeof views === "function") {
                        result = views(url);
                    }
                    else {
                        result = views[url];
                    }
                    if (typeof result === "string") {
                        url = result;
                        result = null;
                    }
                }
                if (!result) {
                    if (url === "_hidden") {
                        result = { hidden: true };
                    }
                    else if (url === "_blank") {
                        result = {};
                    }
                    else {
                        url = url.replace(/\./g, "/");
                        result = this.require("jet-views", url);
                    }
                }
            }
            catch (e) {
                result = this._loadError(url, e);
            }
            if (!result.then) {
                result = Promise.resolve(result);
            }
            result = result
                .then(function (module) { return module.__esModule ? module.default : module; })
                .catch(function (err) { return _this._loadError(url, err); });
            return result;
        };
        JetAppBase.prototype._forView = function (target, handler) {
            var view = this.webix.$$(target);
            if (view) {
                handler(view.$scope);
            }
        };
        JetAppBase.prototype._loadViewDynamic = function (url) {
            return null;
        };
        JetAppBase.prototype.createFromURL = function (chunk) {
            var _this = this;
            var view;
            if (chunk.isNew || !chunk.view) {
                view = this.loadView(chunk.page)
                    .then(function (ui) { return _this.createView(ui, name, chunk.params); });
            }
            else {
                view = Promise.resolve(chunk.view);
            }
            return view;
        };
        JetAppBase.prototype._override = function (ui) {
            var over = this.config.override;
            if (over) {
                var dv = void 0;
                while (ui) {
                    dv = ui;
                    ui = over.get(ui);
                }
                return dv;
            }
            return ui;
        };
        JetAppBase.prototype.createView = function (ui, name, params) {
            ui = this._override(ui);
            var obj;
            if (typeof ui === "function") {
                if (ui.prototype instanceof JetAppBase) {
                    return new ui({ app: this, name: name, params: params, router: SubRouter });
                }
                else if (ui.prototype instanceof JetBase) {
                    return new ui(this, { name: name, params: params });
                }
                else {
                    ui = ui(this);
                }
            }
            if (ui instanceof JetBase) {
                obj = ui;
            }
            else {
                obj = new JetViewRaw(this, { name: name, ui: ui });
            }
            return obj;
        };
        JetAppBase.prototype.show = function (url, config) {
            if (url && this.app && url.indexOf("//") == 0)
                return this.app.show(url.substr(1), config);
            return this.render(this._container, url || this.config.start, config);
        };
        JetAppBase.prototype.trigger = function (name) {
            var rest = [];
            for (var _i = 1; _i < arguments.length; _i++) {
                rest[_i - 1] = arguments[_i];
            }
            this.apply(name, rest);
        };
        JetAppBase.prototype.apply = function (name, data) {
            this.callEvent(name, data);
        };
        JetAppBase.prototype.action = function (name) {
            return this.webix.bind(function () {
                var rest = [];
                for (var _i = 0; _i < arguments.length; _i++) {
                    rest[_i] = arguments[_i];
                }
                this.apply(name, rest);
            }, this);
        };
        JetAppBase.prototype.on = function (name, handler) {
            this.attachEvent(name, handler);
        };
        JetAppBase.prototype.use = function (plugin, config) {
            plugin(this, null, config);
        };
        JetAppBase.prototype.error = function (name, er) {
            this.callEvent(name, er);
            this.callEvent("app:error", er);
            if (this.config.debug) {
                for (var i = 0; i < er.length; i++) {
                    console.error(er[i]);
                    if (er[i] instanceof Error) {
                        var text = er[i].message;
                        if (text.indexOf("Module build failed") === 0) {
                            text = text.replace(/\x1b\[[0-9;]*m/g, "");
                            document.body.innerHTML = "<pre style='font-size:16px; background-color: #ec6873; color: #000; padding:10px;'>" + text + "</pre>";
                        }
                        else {
                            text += "<br><br>Check console for more details";
                            this.webix.message({ type: "error", text: text, expire: -1 });
                        }
                    }
                }
                debugger;
            }
        };
        JetAppBase.prototype.render = function (root, url, config) {
            var _this = this;
            this._container = (typeof root === "string") ?
                this.webix.toNode(root) :
                (root || document.body);
            var firstInit = !this.$router;
            var path = null;
            if (firstInit) {
                if (_once && "tagName" in this._container) {
                    this.webix.event(document.body, "click", function (e) { return _this.clickHandler(e); });
                    _once = false;
                }
                if (typeof url === "string") {
                    url = new Route(url, 0);
                }
                this._subSegment = this._first_start(url);
                this._subSegment.route.linkRouter = true;
            }
            else {
                if (typeof url === "string") {
                    path = url;
                }
                else {
                    if (this.app) {
                        path = url.split().route.path || this.config.start;
                    }
                    else {
                        path = url.toString();
                    }
                }
            }
            var params = config ? config.params : this.config.params || null;
            var top = this.getSubView();
            var segment = this._subSegment;
            var ready = segment
                .show({ url: path, params: params }, top)
                .then(function () { return _this.createFromURL(segment.current()); })
                .then(function (view) { return view.render(root, segment); })
                .then(function (base) {
                _this.$router.set(segment.route.path, { silent: true });
                _this.callEvent("app:route", [_this.getUrl()]);
                return base;
            });
            this.ready = this.ready.then(function () { return ready; });
            return ready;
        };
        JetAppBase.prototype.getSubView = function () {
            if (this._subSegment) {
                var view = this._subSegment.current().view;
                if (view)
                    return view;
            }
            return new JetView(this, {});
        };
        JetAppBase.prototype.require = function (type, url) { return null; };
        JetAppBase.prototype._first_start = function (route) {
            var _this = this;
            this._segment = route;
            var cb = function (a) { return setTimeout(function () {
                _this.show(a).catch(function (e) {
                    if (!(e instanceof NavigationBlocked))
                        throw e;
                });
            }, 1); };
            this.$router = new (this.config.router)(cb, this.config, this);
            if (this._container === document.body && this.config.animation !== false) {
                var node_1 = this._container;
                this.webix.html.addCss(node_1, "webixappstart");
                setTimeout(function () {
                    _this.webix.html.removeCss(node_1, "webixappstart");
                    _this.webix.html.addCss(node_1, "webixapp");
                }, 10);
            }
            if (!route) {
                var urlString = this.$router.get();
                if (!urlString) {
                    urlString = this.config.start;
                    this.$router.set(urlString, { silent: true });
                }
                route = new Route(urlString, 0);
            }
            else if (this.app) {
                var now = route.current().view;
                route.current().view = this;
                if (route.next()) {
                    route.refresh();
                    route = route.split();
                }
                else {
                    route = new Route(this.config.start, 0);
                }
                route.current().view = now;
            }
            return route;
        };
        JetAppBase.prototype._loadError = function (url, err) {
            this.error("app:error:resolve", [err, url]);
            return { template: " " };
        };
        JetAppBase.prototype.addSubView = function (obj, target, config) {
            var url = obj.$subview !== true ? obj.$subview : null;
            var name = obj.name || (url ? this.webix.uid() : "default");
            target.id = obj.id || "s" + this.webix.uid();
            var view = config[name] = {
                id: target.id,
                url: url,
                branch: obj.branch,
                popup: obj.popup,
                params: obj.params
            };
            return view.popup ? null : target;
        };
        return JetAppBase;
    }(JetBase));
    var HashRouter = (function () {
        function HashRouter(cb, config) {
            var _this = this;
            this.config = config || {};
            this._detectPrefix();
            this.cb = cb;
            window.onpopstate = function () { return _this.cb(_this.get()); };
        }
        HashRouter.prototype.set = function (path, config) {
            var _this = this;
            if (this.config.routes) {
                var compare = path.split("?", 2);
                for (var key in this.config.routes) {
                    if (this.config.routes[key] === compare[0]) {
                        path = key + (compare.length > 1 ? "?" + compare[1] : "");
                        break;
                    }
                }
            }
            if (this.get() !== path) {
                window.history.pushState(null, null, this.prefix + this.sufix + path);
            }
            if (!config || !config.silent) {
                setTimeout(function () { return _this.cb(path); }, 1);
            }
        };
        HashRouter.prototype.get = function () {
            var path = this._getRaw().replace(this.prefix, "").replace(this.sufix, "");
            path = (path !== "/" && path !== "#") ? path : "";
            if (this.config.routes) {
                var compare = path.split("?", 2);
                var key = this.config.routes[compare[0]];
                if (key) {
                    path = key + (compare.length > 1 ? "?" + compare[1] : "");
                }
            }
            return path;
        };
        HashRouter.prototype._detectPrefix = function () {
            var sufix = this.config.routerPrefix;
            this.sufix = "#" + ((typeof sufix === "undefined") ? "!" : sufix);
            this.prefix = document.location.href.split("#", 2)[0];
        };
        HashRouter.prototype._getRaw = function () {
            return document.location.href;
        };
        return HashRouter;
    }());
    var isPatched = false;
    function patch(w) {
        if (isPatched || !w) {
            return;
        }
        isPatched = true;
        var win = window;
        if (!win.Promise) {
            win.Promise = w.promise;
        }
        var version = w.version.split(".");
        if (version[0] * 10 + version[1] * 1 < 53) {
            w.ui.freeze = function (handler) {
                var res = handler();
                if (res && res.then) {
                    res.then(function (some) {
                        w.ui.$freeze = false;
                        w.ui.resize();
                        return some;
                    });
                }
                else {
                    w.ui.$freeze = false;
                    w.ui.resize();
                }
                return res;
            };
        }
        var baseAdd = w.ui.baselayout.prototype.addView;
        var baseRemove = w.ui.baselayout.prototype.removeView;
        var config = {
            addView: function (view, index) {
                if (this.$scope && this.$scope.webixJet && !view.queryView) {
                    var jview_1 = this.$scope;
                    var subs_1 = {};
                    view = jview_1.app.copyConfig(view, {}, subs_1);
                    baseAdd.apply(this, [view, index]);
                    var _loop_1 = function (key) {
                        jview_1._renderFrame(key, subs_1[key], null).then(function () {
                            jview_1._subs[key] = subs_1[key];
                        });
                    };
                    for (var key in subs_1) {
                        _loop_1(key);
                    }
                    return view.id;
                }
                else {
                    return baseAdd.apply(this, arguments);
                }
            },
            removeView: function () {
                baseRemove.apply(this, arguments);
                if (this.$scope && this.$scope.webixJet) {
                    var subs = this.$scope._subs;
                    for (var key in subs) {
                        var test = subs[key];
                        if (!w.$$(test.id)) {
                            test.view.destructor();
                            delete subs[key];
                        }
                    }
                }
            }
        };
        w.extend(w.ui.layout.prototype, config, true);
        w.extend(w.ui.baselayout.prototype, config, true);
        w.protoUI({
            name: "jetapp",
            $init: function (cfg) {
                this.$app = new this.app(cfg);
                var id = w.uid().toString();
                cfg.body = { id: id };
                this.$ready.push(function () {
                    this.callEvent("onInit", [this.$app]);
                    this.$app.render({ id: id });
                });
            }
        }, w.ui.proxy, w.EventSystem);
    }
    var JetApp = (function (_super) {
        __extends(JetApp, _super);
        function JetApp(config) {
            var _this = this;
            config.router = config.router || HashRouter;
            _this = _super.call(this, config) || this;
            patch(_this.webix);
            return _this;
        }
        JetApp.prototype.require = function (type, url) {
            return require(type + "/" + url);
        };
        return JetApp;
    }(JetAppBase));
    var UrlRouter = (function (_super) {
        __extends(UrlRouter, _super);
        function UrlRouter() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        UrlRouter.prototype._detectPrefix = function () {
            this.prefix = "";
            this.sufix = this.config.routerPrefix || "";
        };
        UrlRouter.prototype._getRaw = function () {
            return document.location.pathname + (document.location.search || "");
        };
        return UrlRouter;
    }(HashRouter));
    var EmptyRouter = (function () {
        function EmptyRouter(cb, _$config) {
            this.path = "";
            this.cb = cb;
        }
        EmptyRouter.prototype.set = function (path, config) {
            var _this = this;
            this.path = path;
            if (!config || !config.silent) {
                setTimeout(function () { return _this.cb(path); }, 1);
            }
        };
        EmptyRouter.prototype.get = function () {
            return this.path;
        };
        return EmptyRouter;
    }());
    function UnloadGuard(app, view, config) {
        view.on(app, "app:guard", function (_$url, point, promise) {
            if (point === view || point.contains(view)) {
                var res_1 = config();
                if (res_1 === false) {
                    promise.confirm = Promise.reject(new NavigationBlocked());
                }
                else {
                    promise.confirm = promise.confirm.then(function () { return res_1; });
                }
            }
        });
    }
    function has(store, key) {
        return Object.prototype.hasOwnProperty.call(store, key);
    }
    function forEach(obj, handler, context) {
        for (var key in obj) {
            if (has(obj, key)) {
                handler.call((context || obj), obj[key], key, obj);
            }
        }
    }
    function trim(str) {
        return str.replace(/^[\s\uFEFF\xA0]+|[\s\uFEFF\xA0]+$/g, '');
    }
    function warn(message) {
        message = 'Warning: ' + message;
        if (typeof console !== 'undefined') {
            console.error(message);
        }
        try {
            throw new Error(message);
        }
        catch (x) { }
    }
    var replace = String.prototype.replace;
    var split = String.prototype.split;
    var delimiter = '||||';
    var russianPluralGroups = function (n) {
        var end = n % 10;
        if (n !== 11 && end === 1) {
            return 0;
        }
        if (2 <= end && end <= 4 && !(n >= 12 && n <= 14)) {
            return 1;
        }
        return 2;
    };
    var pluralTypes = {
        arabic: function (n) {
            if (n < 3) {
                return n;
            }
            var lastTwo = n % 100;
            if (lastTwo >= 3 && lastTwo <= 10)
                return 3;
            return lastTwo >= 11 ? 4 : 5;
        },
        bosnian_serbian: russianPluralGroups,
        chinese: function () { return 0; },
        croatian: russianPluralGroups,
        french: function (n) { return n > 1 ? 1 : 0; },
        german: function (n) { return n !== 1 ? 1 : 0; },
        russian: russianPluralGroups,
        lithuanian: function (n) {
            if (n % 10 === 1 && n % 100 !== 11) {
                return 0;
            }
            return n % 10 >= 2 && n % 10 <= 9 && (n % 100 < 11 || n % 100 > 19) ? 1 : 2;
        },
        czech: function (n) {
            if (n === 1) {
                return 0;
            }
            return (n >= 2 && n <= 4) ? 1 : 2;
        },
        polish: function (n) {
            if (n === 1) {
                return 0;
            }
            var end = n % 10;
            return 2 <= end && end <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2;
        },
        icelandic: function (n) { return (n % 10 !== 1 || n % 100 === 11) ? 1 : 0; },
        slovenian: function (n) {
            var lastTwo = n % 100;
            if (lastTwo === 1) {
                return 0;
            }
            if (lastTwo === 2) {
                return 1;
            }
            if (lastTwo === 3 || lastTwo === 4) {
                return 2;
            }
            return 3;
        }
    };
    var pluralTypeToLanguages = {
        arabic: ['ar'],
        bosnian_serbian: ['bs-Latn-BA', 'bs-Cyrl-BA', 'srl-RS', 'sr-RS'],
        chinese: ['id', 'id-ID', 'ja', 'ko', 'ko-KR', 'lo', 'ms', 'th', 'th-TH', 'zh'],
        croatian: ['hr', 'hr-HR'],
        german: ['fa', 'da', 'de', 'en', 'es', 'fi', 'el', 'he', 'hi-IN', 'hu', 'hu-HU', 'it', 'nl', 'no', 'pt', 'sv', 'tr'],
        french: ['fr', 'tl', 'pt-br'],
        russian: ['ru', 'ru-RU'],
        lithuanian: ['lt'],
        czech: ['cs', 'cs-CZ', 'sk'],
        polish: ['pl'],
        icelandic: ['is'],
        slovenian: ['sl-SL']
    };
    function langToTypeMap(mapping) {
        var ret = {};
        forEach(mapping, function (langs, type) {
            forEach(langs, function (lang) {
                ret[lang] = type;
            });
        });
        return ret;
    }
    function pluralTypeName(locale) {
        var langToPluralType = langToTypeMap(pluralTypeToLanguages);
        return langToPluralType[locale]
            || langToPluralType[split.call(locale, /-/, 1)[0]]
            || langToPluralType.en;
    }
    function pluralTypeIndex(locale, count) {
        return pluralTypes[pluralTypeName(locale)](count);
    }
    function escape(token) {
        return token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    function constructTokenRegex(opts) {
        var prefix = (opts && opts.prefix) || '%{';
        var suffix = (opts && opts.suffix) || '}';
        if (prefix === delimiter || suffix === delimiter) {
            throw new RangeError('"' + delimiter + '" token is reserved for pluralization');
        }
        return new RegExp(escape(prefix) + '(.*?)' + escape(suffix), 'g');
    }
    var dollarRegex = /\$/g;
    var dollarBillsYall = '$$';
    var defaultTokenRegex = /%\{(.*?)\}/g;
    function transformPhrase(phrase, substitutions, locale, tokenRegex) {
        if (typeof phrase !== 'string') {
            throw new TypeError('Polyglot.transformPhrase expects argument #1 to be string');
        }
        if (substitutions == null) {
            return phrase;
        }
        var result = phrase;
        var interpolationRegex = tokenRegex || defaultTokenRegex;
        var options = typeof substitutions === 'number' ? { smart_count: substitutions } : substitutions;
        if (options.smart_count != null && result) {
            var texts = split.call(result, delimiter);
            result = trim(texts[pluralTypeIndex(locale || 'en', options.smart_count)] || texts[0]);
        }
        result = replace.call(result, interpolationRegex, function (expression, argument) {
            if (!has(options, argument) || options[argument] == null) {
                return expression;
            }
            return replace.call(options[argument], dollarRegex, dollarBillsYall);
        });
        return result;
    }
    function Polyglot(options) {
        var opts = options || {};
        this.phrases = {};
        this.extend(opts.phrases || {});
        this.currentLocale = opts.locale || 'en';
        var allowMissing = opts.allowMissing ? transformPhrase : null;
        this.onMissingKey = typeof opts.onMissingKey === 'function' ? opts.onMissingKey : allowMissing;
        this.warn = opts.warn || warn;
        this.tokenRegex = constructTokenRegex(opts.interpolation);
    }
    Polyglot.prototype.locale = function (newLocale) {
        if (newLocale)
            this.currentLocale = newLocale;
        return this.currentLocale;
    };
    Polyglot.prototype.extend = function (morePhrases, prefix) {
        forEach(morePhrases, function (phrase, key) {
            var prefixedKey = prefix ? prefix + '.' + key : key;
            if (typeof phrase === 'object') {
                this.extend(phrase, prefixedKey);
            }
            else {
                this.phrases[prefixedKey] = phrase;
            }
        }, this);
    };
    Polyglot.prototype.unset = function (morePhrases, prefix) {
        if (typeof morePhrases === 'string') {
            delete this.phrases[morePhrases];
        }
        else {
            forEach(morePhrases, function (phrase, key) {
                var prefixedKey = prefix ? prefix + '.' + key : key;
                if (typeof phrase === 'object') {
                    this.unset(phrase, prefixedKey);
                }
                else {
                    delete this.phrases[prefixedKey];
                }
            }, this);
        }
    };
    Polyglot.prototype.clear = function () {
        this.phrases = {};
    };
    Polyglot.prototype.replace = function (newPhrases) {
        this.clear();
        this.extend(newPhrases);
    };
    Polyglot.prototype.t = function (key, options) {
        var phrase, result;
        var opts = options == null ? {} : options;
        if (typeof this.phrases[key] === 'string') {
            phrase = this.phrases[key];
        }
        else if (typeof opts._ === 'string') {
            phrase = opts._;
        }
        else if (this.onMissingKey) {
            var onMissingKey = this.onMissingKey;
            result = onMissingKey(key, opts, this.currentLocale, this.tokenRegex);
        }
        else {
            this.warn('Missing translation for key: "' + key + '"');
            result = key;
        }
        if (typeof phrase === 'string') {
            result = transformPhrase(phrase, opts, this.currentLocale, this.tokenRegex);
        }
        return result;
    };
    Polyglot.prototype.has = function (key) {
        return has(this.phrases, key);
    };
    Polyglot.transformPhrase = function transform(phrase, substitutions, locale) {
        return transformPhrase(phrase, substitutions, locale);
    };
    var webixPolyglot = Polyglot;
    function Locale(app, _view, config) {
        config = config || {};
        var storage = config.storage;
        var lang = storage ? (storage.get("lang") || "en") : (config.lang || "en");
        function setLangData(name, data, silent) {
            if (data.__esModule) {
                data = data.default;
            }
            var pconfig = { phrases: data };
            if (config.polyglot) {
                app.webix.extend(pconfig, config.polyglot);
            }
            var poly = service.polyglot = new webixPolyglot(pconfig);
            poly.locale(name);
            service._ = app.webix.bind(poly.t, poly);
            lang = name;
            if (storage) {
                storage.put("lang", lang);
            }
            if (config.webix) {
                var locName = config.webix[name];
                if (locName) {
                    app.webix.i18n.setLocale(locName);
                }
            }
            if (!silent) {
                return app.refresh();
            }
            return Promise.resolve();
        }
        function getLang() { return lang; }
        function setLang(name, silent) {
            if (config.path === false) {
                return;
            }
            var path = (config.path ? config.path + "/" : "") + name;
            var data = app.require("jet-locales", path);
            setLangData(name, data, silent);
        }
        var service = {
            getLang: getLang, setLang: setLang, setLangData: setLangData, _: null, polyglot: null
        };
        app.setService("locale", service);
        setLang(lang, true);
    }
    function show(view, config, value) {
        var _a;
        if (config.urls) {
            value = config.urls[value] || value;
        }
        else if (config.param) {
            value = (_a = {}, _a[config.param] = value, _a);
        }
        view.show(value);
    }
    function Menu(app, view, config) {
        var frame = view.getSubViewInfo().parent;
        var ui = view.$$(config.id || config);
        var silent = false;
        ui.attachEvent("onchange", function () {
            if (!silent) {
                show(frame, config, this.getValue());
            }
        });
        ui.attachEvent("onafterselect", function () {
            if (!silent) {
                var id = null;
                if (ui.setValue) {
                    id = this.getValue();
                }
                else if (ui.getSelectedId) {
                    id = ui.getSelectedId();
                }
                show(frame, config, id);
            }
        });
        view.on(app, "app:route", function () {
            var name = "";
            if (config.param) {
                name = view.getParam(config.param, true);
            }
            else {
                var segment = frame.getUrl()[1];
                if (segment) {
                    name = segment.page;
                }
            }
            if (name) {
                silent = true;
                if (ui.setValue && ui.getValue() !== name) {
                    ui.setValue(name);
                }
                else if (ui.select && ui.exists(name) && ui.getSelectedId() !== name) {
                    ui.select(name);
                }
                silent = false;
            }
        });
    }
    var baseicons = {
        good: "check",
        error: "warning",
        saving: "refresh fa-spin"
    };
    var basetext = {
        good: "Ok",
        error: "Error",
        saving: "Connecting..."
    };
    function Status(app, view, config) {
        var status = "good";
        var count = 0;
        var iserror = false;
        var expireDelay = config.expire;
        if (!expireDelay && expireDelay !== false) {
            expireDelay = 2000;
        }
        var texts = config.texts || basetext;
        var icons = config.icons || baseicons;
        if (typeof config === "string") {
            config = { target: config };
        }
        function refresh(content) {
            var area = view.$$(config.target);
            if (area) {
                if (!content) {
                    content = "<div class='status_" +
                        status +
                        "'><span class='webix_icon fa-" +
                        icons[status] + "'></span> " + texts[status] + "</div>";
                }
                area.setHTML(content);
            }
        }
        function success() {
            count--;
            setStatus("good");
        }
        function fail(err) {
            count--;
            setStatus("error", err);
        }
        function start(promise) {
            count++;
            setStatus("saving");
            if (promise && promise.then) {
                promise.then(success, fail);
            }
        }
        function getStatus() {
            return status;
        }
        function hideStatus() {
            if (count === 0) {
                refresh(" ");
            }
        }
        function setStatus(mode, err) {
            if (count < 0) {
                count = 0;
            }
            if (mode === "saving") {
                status = "saving";
                refresh();
            }
            else {
                iserror = (mode === "error");
                if (count === 0) {
                    status = iserror ? "error" : "good";
                    if (iserror) {
                        app.error("app:error:server", [err.responseText || err]);
                    }
                    else {
                        if (expireDelay) {
                            setTimeout(hideStatus, expireDelay);
                        }
                    }
                    refresh();
                }
            }
        }
        function track(data) {
            var dp = app.webix.dp(data);
            if (dp) {
                view.on(dp, "onAfterDataSend", start);
                view.on(dp, "onAfterSaveError", function (_id, _obj, response) { return fail(response); });
                view.on(dp, "onAfterSave", success);
            }
        }
        app.setService("status", {
            getStatus: getStatus,
            setStatus: setStatus,
            track: track
        });
        if (config.remote) {
            view.on(app.webix, "onRemoteCall", start);
        }
        if (config.ajax) {
            view.on(app.webix, "onBeforeAjax", function (_mode, _url, _data, _request, _headers, _files, promise) {
                start(promise);
            });
        }
        if (config.data) {
            track(config.data);
        }
    }
    function Theme(app, _view, config) {
        config = config || {};
        var storage = config.storage;
        var theme = storage ?
            (storage.get("theme") || "flat-default")
            :
                (config.theme || "flat-default");
        var service = {
            getTheme: function () { return theme; },
            setTheme: function (name, silent) {
                var parts = name.split("-");
                var links = document.getElementsByTagName("link");
                for (var i = 0; i < links.length; i++) {
                    var lname = links[i].getAttribute("title");
                    if (lname) {
                        if (lname === name || lname === parts[0]) {
                            links[i].disabled = false;
                        }
                        else {
                            links[i].disabled = true;
                        }
                    }
                }
                app.webix.skin.set(parts[0]);
                app.webix.html.removeCss(document.body, "theme-" + theme);
                app.webix.html.addCss(document.body, "theme-" + name);
                theme = name;
                if (storage) {
                    storage.put("theme", name);
                }
                if (!silent) {
                    app.refresh();
                }
            }
        };
        app.setService("theme", service);
        service.setTheme(theme, true);
    }
    function copyParams(data, url, route) {
        for (var i = 0; i < route.length; i++) {
            data[route[i]] = url[i + 1] ? url[i + 1].page : "";
        }
    }
    function UrlParam(app, view, config) {
        var route = config.route || config;
        var data = {};
        view.on(app, "app:urlchange", function (subview, segment) {
            if (view === subview) {
                copyParams(data, segment.suburl(), route);
                segment.size(route.length + 1);
            }
        });
        var os = view.setParam;
        var og = view.getParam;
        view.setParam = function (name, value, show) {
            var index = route.indexOf(name);
            if (index >= 0) {
                data[name] = value;
                this._segment.update("", value, index + 1);
                if (show) {
                    return view.show(null);
                }
            }
            else {
                return os.call(this, name, value, show);
            }
        };
        view.getParam = function (key, mode) {
            var val = data[key];
            if (typeof val !== "undefined") {
                return val;
            }
            return og.call(this, key, mode);
        };
        copyParams(data, view.getUrl(), route);
    }
    function User(app, _view, config) {
        config = config || {};
        var login = config.login || "/login";
        var logout = config.logout || "/logout";
        var afterLogin = config.afterLogin || app.config.start;
        var afterLogout = config.afterLogout || "/login";
        var ping = config.ping || 5 * 60 * 1000;
        var model = config.model;
        var user = config.user;
        var service = {
            getUser: function () {
                return user;
            },
            getStatus: function (server) {
                if (!server) {
                    return user !== null;
                }
                return model.status().catch(function () { return null; }).then(function (data) {
                    user = data;
                });
            },
            login: function (name, pass) {
                return model.login(name, pass).then(function (data) {
                    user = data;
                    if (!data) {
                        throw new Error("Access denied");
                    }
                    app.callEvent("app:user:login", [user]);
                    app.show(afterLogin);
                });
            },
            logout: function () {
                user = null;
                return model.logout().then(function (res) {
                    app.callEvent("app:user:logout", []);
                    return res;
                });
            }
        };
        function canNavigate(url, obj) {
            if (url === logout) {
                service.logout();
                obj.redirect = afterLogout;
            }
            else if (url !== login && !service.getStatus()) {
                obj.redirect = login;
            }
        }
        app.setService("user", service);
        app.attachEvent("app:guard", function (url, _$root, obj) {
            if (config.public && config.public(url)) {
                return true;
            }
            if (typeof user === "undefined") {
                obj.confirm = service.getStatus(true).then(function () { return canNavigate(url, obj); });
            }
            return canNavigate(url, obj);
        });
        if (ping) {
            setInterval(function () { return service.getStatus(true); }, ping);
        }
    }
    var webix$1 = window.webix;
    if (webix$1) {
        patch(webix$1);
    }
    var plugins = {
        UnloadGuard: UnloadGuard, Locale: Locale, Menu: Menu, Theme: Theme, User: User, Status: Status, UrlParam: UrlParam
    };
    var w = window;
    if (!w.Promise) {
        w.Promise = w.webix.promise;
    }

    var index = 1;
    function uid() {
        return index++;
    }
    var empty = undefined;
    var context = null;
    function link(source, target, key) {
        Object.defineProperty(target, key, {
            get: function () { return source[key]; },
            set: function (value) { return (source[key] = value); },
        });
    }
    function createState(data, config) {
        config = config || {};
        var handlers = {};
        var out = {};
        var observe = function (mask, handler) {
            var key = uid();
            handlers[key] = { mask: mask, handler: handler };
            if (mask === "*")
                handler(out, empty, mask);
            else
                handler(out[mask], empty, mask);
            return key;
        };
        var observeEnd = function (id) {
            delete handlers[id];
        };
        var queue = [];
        var waitInQueue = false;
        var batch = function (code) {
            if (typeof code !== "function") {
                var values_1 = code;
                code = function () {
                    for (var key in values_1)
                        out[key] = values_1[key];
                };
            }
            waitInQueue = true;
            code(out);
            waitInQueue = false;
            while (queue.length) {
                var obj = queue.shift();
                notify.apply(this, obj);
            }
        };
        var notify = function (key, old, value, meta) {
            if (waitInQueue) {
                queue.push([key, old, value, meta]);
                return;
            }
            var list = Object.keys(handlers);
            for (var i = 0; i < list.length; i++) {
                var obj = handlers[list[i]];
                if (!obj)
                    continue;
                if (obj.mask === "*" || obj.mask === key) {
                    obj.handler(value, old, key, meta);
                }
            }
        };
        for (var key2 in data) {
            if (data.hasOwnProperty(key2)) {
                var test = data[key2];
                if (config.nested && typeof test === "object" && test) {
                    out[key2] = createState(test, config);
                }
                else {
                    reactive(out, test, key2, notify);
                }
            }
        }
        Object.defineProperty(out, "$changes", {
            value: {
                attachEvent: observe,
                detachEvent: observeEnd,
            },
            enumerable: false,
            configurable: false,
        });
        Object.defineProperty(out, "$observe", {
            value: observe,
            enumerable: false,
            configurable: false,
        });
        Object.defineProperty(out, "$batch", {
            value: batch,
            enumerable: false,
            configurable: false,
        });
        return out;
    }
    function reactive(obj, val, key, notify) {
        Object.defineProperty(obj, key, {
            get: function () {
                return val;
            },
            set: function (value) {
                var changed = false;
                if (val === null || value === null) {
                    changed = val !== value;
                }
                else {
                    changed = val.valueOf() != value.valueOf();
                }
                if (changed) {
                    var old = val;
                    val = value;
                    notify(key, old, value, context);
                }
            },
            enumerable: true,
            configurable: false,
        });
    }

    function handler(view) {
        return {
            attachEvent: function (key, handler) {
                webix.UIManager.addHotKey(key, handler, view);
                return { key: key, handler: handler };
            },
            detachEvent: function (_a) {
                var key = _a.key, handler = _a.handler;
                return webix.UIManager.removeHotKey(key, handler, view);
            },
        };
    }

    var folderIcon = "<span class='webix_icon wxi-folder'></span>";
    var backIcon = "<span class='webix_icon wxi-angle-double-left webix_fmanager_back_icon'></span>";
    var folderCardIcon = "<span class='webix_icon wxi-folder webix_fmanager_folder_icon'></span>";
    var menuIcon = "<span class='webix_icon wxi-dots webix_fmanager_menu_icon'></span>";
    var gridMode = "<span class='webix_fmanager_icon fmi-view-list webix_fmanager_mode_icon'></span>";
    var cardMode = "<span class='webix_fmanager_icon fmi-view-grid webix_fmanager_mode_icon'></span>";
    var doubleMode = "<span class='webix_fmanager_icon fmi-view-array webix_fmanager_mode_icon'></span>";

    var FOLDER = 0x01;
    var MANY = 0x02;
    var TEXT = 0x04;
    var VIEW = 0x08;
    var FULL = 0x10;
    var TREE = 0x20;
    var SEARCH = 0x40;
    var EMPTY = 0x80;
    var ARCHIVE = 0x100;
    var MenuBodyView = (function (_super) {
        __extends(MenuBodyView, _super);
        function MenuBodyView(app, config) {
            var _this = _super.call(this, app) || this;
            _this.compact = !!config.compact;
            _this.tree = !!config.tree;
            return _this;
        }
        MenuBodyView.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            var options = [
                {
                    id: "download",
                    value: _("Download"),
                    icon: "wxi-download",
                    show: TREE,
                    hotkey: "Ctrl+D",
                },
                {
                    $template: "Separator",
                    show: TREE,
                },
                {
                    id: "toggle-preview",
                    value: _("Preview"),
                    icon: "wxi-eye",
                    show: FULL | FOLDER,
                },
                {
                    id: "locate",
                    value: _("Open item location"),
                    icon: "wxi-folder",
                    hotkey: "Ctrl+Alt+O",
                    show: FOLDER | SEARCH,
                },
                {
                    id: "change-permissions",
                    value: _("Change Permissions"),
                    icon: "wxi-file",
                    show: FOLDER,
                },
                {
                    id: "extract",
                    value: _("Extract"),
                    icon: "clp-zip",
                    show: ARCHIVE,
                },
                {
                    id: "compress",
                    value: _("Compress"),
                    icon: "clp-compress",
                    show: FOLDER | MANY,
                },
                /*
                {
                    id: "open",
                    value: _("Open"),
                    icon: "wxi-folder-open",
                    hotkey: "Ctrl+O",
                    show: VIEW,
                },

                {
                    $template: "Separator",
                    show: VIEW,
                },
                */
                {
                    id: "copy",
                    value: _("Copy"),
                    hotkey: "Ctrl+C",
                    icon: "fmi-content-copy",
                    show: FOLDER | MANY | TREE,
                },
                {
                    id: "cut",
                    value: _("Cut"),
                    hotkey: "Ctrl+X",
                    icon: "fmi-content-cut",
                    show: FOLDER | MANY | TREE,
                },
                {
                    id: "paste",
                    value: _("Paste"),
                    hotkey: "Ctrl+V",
                    icon: "fmi-content-paste",
                    show: FOLDER | TREE | EMPTY,
                },
                {
                    $template: "Separator",
                    show: FOLDER | MANY | TREE,
                },
                {
                    id: "rename",
                    value: _("Rename"),
                    icon: "fmi-rename-box",
                    hotkey: "Ctrl+R",
                    show: FOLDER,
                },
                {
                    id: "delete",
                    value: _("Delete"),
                    icon: "wxi-close",
                    hotkey: "Del / &#8592;",
                    show: FOLDER | MANY,
                },
            ];
            if (this.app.config.editor) {
                options.splice(5, 0, {
                    id: "edit",
                    value: _("Edit"),
                    icon: "wxi-pencil",
                    hotkey: "Ctrl+E",
                    show: TEXT | MANY,
                });
            }
            var menu = {
                view: "menu",
                css: "webix_fmanager_menu",
                layout: "y",
                autoheight: true,
                data: options,
                template: function (obj) {
                    return ((obj.icon
                        ? "<span class=\"webix_list_icon webix_icon " + obj.icon + "\"></span>"
                        : "") +
                        obj.value +
                        (obj.hotkey
                            ? "<span class=\"webix_fmanager_context-menu-hotkey\">" + obj.hotkey + "</span>"
                            : ""));
                },
                on: {
                    onMenuItemClick: function (id) {
                        _this.app.callEvent("app:action", [id, _this.Files]);
                        _this.app.callEvent("app:filemenu:click");
                    },
                },
            };
            return menu;
        };
        MenuBodyView.prototype.FilterOptions = function (files) {
            var _this = this;
            this.Files = files;
            var file = files[0];
            var many = files.length > 1;
            var vtypes = ["image", "audio", "video", "code", "pdf"];
            var viewable = vtypes.find(function (t) { return t === file.type || t === file.$ext; });
            var search = this.app.getState().search;
            var empty = file.type === "empty";
            this.getRoot().define("width", search && !many ? 270 : 200);
            this.getRoot().filter(function (o) {
              return !(
                (!(o.show & EMPTY) && empty) ||
                (many && !(o.show & MANY)) ||
                (file.type === "folder" && !(o.show & FOLDER)) ||
                (o.show & TEXT && file.type !== "code") ||
                (o.show & ARCHIVE && file.type !== "archive") ||
                (o.show & VIEW && !viewable) ||
                (o.show & FULL && !_this.compact) ||
                (o.show & TREE && _this.tree) ||
                (o.show & SEARCH && !search)
              );
            });
        };
        return MenuBodyView;
    }(JetView));

    var ContextMenuView = (function (_super) {
        __extends(ContextMenuView, _super);
        function ContextMenuView(app, config) {
            var _this = _super.call(this, app) || this;
            _this._config = config;
            return _this;
        }
        ContextMenuView.prototype.config = function () {
            var _this = this;
            return {
                view: "context",
                body: {
                    $subview: new (this.app.dynamic(MenuBodyView))(this.app, __assign({}, this._config)),
                    name: "options",
                },
                on: {
                    onBeforeShow: function (e) {
                        var files = _this._Locate(e);
                        if (files)
                            _this.getSubView("options").FilterOptions(files);
                        else
                            return false;
                    },
                },
            };
        };
        ContextMenuView.prototype.init = function () {
            var _this = this;
            this.on(this.app, "app:filemenu:click", function () { return _this.getRoot().hide(); });
        };
        ContextMenuView.prototype.AttachTo = function (master, locate) {
            this._Locate = locate;
            this.getRoot().attachTo(master);
        };
        ContextMenuView.prototype.Show = function (trg) {
            this.getRoot().show(trg, {
                x: -trg.offsetX,
                y: trg.target.offsetHeight - trg.offsetY,
            });
        };
        ContextMenuView.prototype.Hide = function () {
            this.getRoot().hide();
        };
        return ContextMenuView;
    }(JetView));

    var DataViewBase = (function (_super) {
        __extends(DataViewBase, _super);
        function DataViewBase() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        DataViewBase.prototype.init = function () {
            var _this = this;
            this.State = this.getParam("state");
            this.Local = this.app.getService("local");
            this._Track = true;
            this.WTable.attachEvent("onAfterSelect", function () { return _this.ShiftFocus(); });
            this.WTable.attachEvent("onSelectChange", function () {
                if (_this._Track) {
                    var newIDs = []
                        .concat(_this.WTable.getSelectedId(true).map(function (a) { return _this.WTable.getItem(a); }))
                        .filter(function (a) { return a.value !== ".."; });
                    if (newIDs.length || _this.State.selectedItem.length) {
                        if (!newIDs.length)
                            newIDs.$noSelect = true;
                        _this.State.selectedItem = newIDs;
                    }
                }
            });
            this.on(this.State.$changes, "path", function (v, o) {
                if (!_this.State.search) {
                    if (_this.State.source === "files") {
                        if (!o)
                            _this.LoadData(v);
                        else
                            _this.Local.folders().then(function (hierarchy) {
                                _this.State.selectedItem = _this.GetPrevLocation(hierarchy, v, o);
                                _this.LoadData(v);
                            });
                    }
                }
                else if (o) {
                    _this.State.$batch({
                        search: "",
                        searchStats: null,
                    });
                }
            });
            this.on(this.WTable.data, "onStoreUpdated", function (i, o, m) {
                if (!m && _this.WTable.count() && _this.State.selectedItem.length) {
                    _this.SelectActive();
                }
            });
            this.AddHotkeys();
            var compact = this.getParam("compact", true);
            this.Menu = this.ui(new (this.app.dynamic(ContextMenuView))(this.app, {
                compact: compact,
                state: this.State,
            }));
            this.Menu.AttachTo(this.WTable.$view, function (e) {
                var id = _this.WTable.locate(e);
                if (!id)
                    return [{ type: "empty" }];
                var item = _this.WTable.getItem(id);
                if (item) {
                    if (item.value == "..")
                        return false;
                    if (!_this.WTable.isSelected(id))
                        _this.WTable.select(id);
                    var sel = _this.WTable.getSelectedId(true);
                    if (sel.length === 1)
                        return [item];
                    sel = sel.map(function (s) { return _this.WTable.getItem(s); }).filter(function (o) { return o.value != ".."; });
                    return sel;
                }
            });
            this.on(this.WTable, "onAfterScroll", function () { return _this.Menu.Hide(); });
            this.on(this.WTable, "onBeforeDrag", function () { return _this.Menu.Hide(); });
        };
        DataViewBase.prototype.ShiftFocus = function () {
            if (this.getParam("trackActive", true))
                this.State.isActive = true;
        };
        DataViewBase.prototype.Activate = function (items) {
            if (!items.length)
                return;
            if (items.length === 1) {
                var item = items[0];
                if (item.type === "folder") {
                    this.ShowSubFolder(item.value === ".." ? item.value : item.id);
                }
                else {
                    var operation = item.type === "code" && this.app.config.editor ? "edit" : "open";
                    this.app.callEvent("app:action", [operation, items]);
                }
            }
            else {
                var operation = this.app.config.editor ? "edit" : "open";
                this.app.callEvent("app:action", [operation, items]);
            }
        };
        DataViewBase.prototype.ShowSubFolder = function (id) {
            var path;
            if (id == "..") {
                var t = this.State.path.split("/");
                path = "/" + t.slice(1, t.length - 1).join("/");
            }
            else {
                path = id;
            }
            this.State.path = path;
        };
        DataViewBase.prototype.GetPrevLocation = function (hierarchy, path, oldPath) {
            if (oldPath === "/" || !hierarchy.exists(oldPath))
                return [];
            if (path === "/")
                path = "../files";
            var id;
            while (oldPath) {
                id = oldPath;
                oldPath = hierarchy.getParentId(oldPath);
                if (oldPath === path) {
                    var obj = hierarchy.getItem(id);
                    return [
                        {
                            id: obj.id,
                            value: obj.value,
                            type: "folder",
                            date: new Date(obj.date),
                        },
                    ];
                }
            }
            return [];
        };
        DataViewBase.prototype.GetTargetFolder = function (target) {
            var targetItem = target ? this.WTable.getItem(target) : null;
            var invalidTarget = !targetItem || targetItem.type !== "folder" || targetItem.value === "..";
            var targetFolder = invalidTarget ? this.State.path : targetItem.id;
            return targetFolder;
        };
        DataViewBase.prototype.SelectActive = function () {
            var table = this.WTable;
            if (this.State.isActive !== false && !table.getSelectedId()) {
                var sel = this.State.selectedItem;
                for (var i = 0; i < sel.length; i++) {
                    if (table.exists(sel[i].id))
                        table.select(sel[i].id, true);
                }
                if (!sel.$noSelect && !table.getSelectedId()) {
                    var id = table.getFirstId();
                    if (id) {
                        table.select(id);
                        sel.$noSelect = false;
                    }
                }
                webix.delay(function () { return webix.UIManager.setFocus(table); });
            }
        };
        DataViewBase.prototype.GetSelection = function () {
            var _this = this;
            return this.WTable.getSelectedId(true).map(function (file) {
                return _this.WTable.getItem(file);
            });
        };
        DataViewBase.prototype.LoadData = function (path, search) {
            var _this = this;
            this._Track = false;
            var table = this.WTable;
            if (search) {
                table.clearAll();
                this.app
                    .getService("backend")
                    .search(path, search)
                    .then(function (data) {
                    if (_this.app) {
                        for (var i = 0; i < data.length; ++i)
                            _this.Local.prepareData(data[i]);
                        table.parse(data);
                        _this.GetSearchStats(data);
                    }
                });
            }
            else {
                if (table.data.url !== path) {
                    table.clearAll();
                    this.Local.files(path).then(function (data) {
                        if (_this.app) {
                            _this.RenderData(data);
                            _this.SelectActive();
                        }
                    });
                }
            }
            this._Track = true;
        };
        DataViewBase.prototype.MoveFiles = function (source, target) {
            this.app
                .getService("operations")
                .move(source, this.GetTargetFolder(target));
            return false;
        };
        DataViewBase.prototype.Icon = function (obj) {
            return "<img class=\"webix_fmanager_file-type-icon\" src=\"" + this.app
                .getService("backend")
                .icon(obj) + "\" />";
        };
        DataViewBase.prototype.DragMarker = function (ctx) {
            var list = this.WTable;
            var parent = list.find(function (f) { return f.value === ".."; }, true);
            if (parent) {
                var parentInd = ctx.source.indexOf(parent.id);
                if (parentInd !== -1)
                    ctx.source.splice(parentInd, 1);
            }
            var files = ctx.source.length;
            if (!files)
                return false;
            if (files === 1)
                list.select(ctx.source[0]);
            var firstDragged = list.getItem(ctx.source[0]);
            var icon;
            if (firstDragged.type === "folder") {
                icon = folderIcon;
            }
            else {
                icon = this.Icon(firstDragged);
            }
            var html = "<div class='webix_fmanager_grid_drag_zone_list'>";
            html += "<div class=\"webix_fmanager_inner_drag_zone_list\">" + icon + firstDragged.value + "</div>";
            html += "</div>";
            if (files > 1) {
                html = "<div class='webix_drag_main'>" + html + "</div>";
                html += "<div class='webix_badge'>" + files + "</div>";
                var multiple = "<div class='webix_drag_multiple'></div>";
                if (files > 2)
                    multiple = "<div class='webix_drag_multiple_last'></div>" + multiple;
                html = multiple + html;
            }
            ctx.html = html;
        };
        DataViewBase.prototype.AddHotkeys = function () {
            var _this = this;
            var ctrlKey = webix.env.isMac ? "COMMAND" : "CTRL";
            var operations = [
                { key: "DELETE", oper: "delete" },
                { key: "BACKSPACE", oper: "delete" },
                { key: ctrlKey + "+C", oper: "copy" },
                { key: ctrlKey + "+X", oper: "cut" },
                { key: ctrlKey + "+V", oper: "paste" },
                { key: ctrlKey + "+R", oper: "rename" },
                { key: ctrlKey + "+O", oper: "open" },
                { key: ctrlKey + "+D", oper: "download" },
                { key: ctrlKey + "+Alt+O", oper: "locate" },
            ];
            if (this.app.config.editor) {
                operations.push({ key: ctrlKey + "+E", oper: "edit" });
            }
            var _loop_1 = function (i) {
                this_1.on(handler(this_1.getRoot()), operations[i].key, function (v, e) {
                    var params = [operations[i].oper];
                    _this.app.callEvent("app:action", params);
                    webix.html.preventEvent(e);
                });
            };
            var this_1 = this;
            for (var i = 0; i < operations.length; ++i) {
                _loop_1(i);
            }
            this.on(handler(this.getRoot()), ctrlKey + "+A", function (v, e) {
                _this.WTable.selectAll();
                webix.html.preventEvent(e);
            });
        };
        DataViewBase.prototype.GetSearchStats = function (data) {
            var all = data.length;
            var folders = data.filter(function (i) { return i.type === "folder"; });
            this.State.searchStats = {
                folders: folders ? folders.length : 0,
                files: all - folders.length,
            };
        };
        DataViewBase.prototype.EmptyClick = function () {
            if (this.State.isActive !== false)
                this.WTable.unselectAll();
            else
                this.ShiftFocus();
        };
        return DataViewBase;
    }(JetView));

    var CardsView = (function (_super) {
        __extends(CardsView, _super);
        function CardsView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        CardsView.prototype.config = function () {
            var _this = this;
            var compact = this.getParam("compact", true);
            var smallSkin = webix.skin.$name === "mini" || webix.skin.$name === "compact";
            var itemSize = smallSkin
                ? {
                    height: 149,
                    width: 181,
                    padding: 16,
                }
                : {
                    height: 197,
                    width: 236,
                    padding: 20,
                };
            return {
                view: "dataview",
                localId: "cards",
                drag: !compact,
                select: true,
                multiselect: true,
                css: "webix_noselect webix_fmanager_cards",
                template: function (obj) { return _this.CardTemplate(obj); },
                type: __assign(__assign({}, itemSize), { type: "tiles" }),
                on: {
                    onItemDblClick: function () { return _this.Activate(_this.GetSelection()); },
                    onEnter: function () { return _this.Activate(_this.GetSelection()); },
                    onBeforeDrag: function (ctx) { return _this.DragMarker(ctx); },
                    onBeforeDrop: function (ctx) { return _this.MoveFiles(ctx.source, ctx.target); },
                },
                onClick: {
                    webix_fmanager_menu_icon: function (e) {
                        _this.Menu.Show(e);
                    },
                    webix_dataview: function (e, id) {
                        if (!id)
                            _this.EmptyClick();
                    },
                },
                onContext: {
                    webix_dataview: function (e, id) {
                        if (!id)
                            _this.EmptyClick();
                    },
                },
            };
        };
        CardsView.prototype.init = function () {
            this.WTable = this.$$("cards");
            _super.prototype.init.call(this);
        };
        CardsView.prototype.RenderData = function (data) {
            var _this = this;
            this.WTable.sync(data, function () { return _this.WTable.filter(function (f) { return f.value !== ".."; }); });
        };
        CardsView.prototype.CardTemplate = function (obj) {
            var _ = this.app.getService("locale")._;
            var preview, panel;
            if (obj.type === "folder") {
                preview = "<div class=\"webix_fmanager_card_preview\">" + folderCardIcon + "</div>";
                panel = "<div class=\"webix_fmanager_card_panel\">\n\t\t<span class=\"webix_fmanager_card_label\">" + _("Folder") + "</span>\n\t\t<span class=\"webix_fmanager_card_name folder\">" + this.SearchTemplate(obj.value) + "</span>" + menuIcon + "\n\t\t</div>";
            }
            else {
                var skin = webix.skin.$active;
                var picSize = skin.listItemHeight < 29 ? [163, 92] : [214, 124];
                var origin_1 = this.app
                    .getService("backend")
                    .previewURL(obj, picSize[0], picSize[1]);
                var img = "<img height=\"" + picSize[1] + "\" width=\"" + picSize[0] + "\" src=\"" + origin_1 + "\" onerror='this.style.display=\"none\"'/>";
                preview = "<div class=\"webix_fmanager_card_preview file\">" + img + "</div>";
                var fileIcon = this.Icon(obj);
                panel = "<div class=\"webix_fmanager_card_panel file\">\n\t\t<span class=\"webix_fmanager_card_name\">" + fileIcon + "<span class=\"file_name_text\">" + this.SearchTemplate(obj.value) + "</span></span>" + menuIcon + "\n\t\t</div>";
            }
            return preview + panel;
        };
        CardsView.prototype.SearchTemplate = function (name) {
            if (this.State.search) {
                var rex = new RegExp("(" + this.State.search + ")", "gi");
                return name.replace(rex, "<span class='webix_fmanager_search_mark'>$1</span>");
            }
            return name;
        };
        return CardsView;
    }(DataViewBase));

    webix.protoUI({
        name: "codemirror-editor",
        defaults: {
            mode: "php",
            lineNumbers: true,
            matchBrackets: true,
            theme: "default"
        },
        $init: function (config) {
            this.$view.innerHTML = "<textarea style='width:100%;height:100%;'></textarea>";
            this._textarea = this.$view.firstChild;
            this._waitEditor = webix.promise.defer();
            this.$ready.push(this._render_cm_editor);
        },
        complex_types: {
            php: {
                mode: ["xml", "javascript", "css", "htmlmixed", "clike"]
            },
            htmlembedded: {
                mode: ["xml", "javascript", "css", "htmlmixed"],
                addon: ["mode/multiplex"]
            },
            htmlmixed: {
                mode: ["xml", "javascript", "css"]
            },
            dockerfile: {
                addon: ["mode/simple"]
            }
        },
        _render_cm_editor: function () {
            if (this.config.cdn === false) {
                this._render_when_ready;
                return;
            }
            var cdn = this.config.cdn || "https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.48.4";
            var sources = [
                cdn + "/codemirror.min.css",
                cdn + "/codemirror.min.js"
            ];
            var mode = this.config.mode ? this.config.mode : "javascript";
            var extras = this.complex_types[mode];
            if (extras) {
                if (extras["mode"]) {
                    extras["mode"].forEach(function (name) {
                        var path = "/mode/" + name + "/" + name + ".min.js";
                        sources.push(cdn + path);
                    });
                }
                if (extras["addon"]) {
                    extras["addon"].forEach(function (name) {
                        var path = "/addon/" + name + ".js";
                        sources.push(cdn + path);
                    });
                }
            }
            sources.push(cdn + "/mode/" + mode + "/" + mode + ".js");
            if (this.config.theme && this.config.theme !== "default") {
                sources.push(cdn + "/theme/" + this.config.theme + ".css");
            }
            if (this.config.matchBrackets) {
                sources.push(cdn + "/addon/edit/matchbrackets.js");
            }
            webix.require(sources)
                .then(webix.bind(this._render_when_ready, this))
                .catch(function (e) {
                console.log(e);
            });
        },
        _render_when_ready: function () {
            this._editor = CodeMirror.fromTextArea(this._textarea, {
                mode: this.config.mode,
                lineNumbers: this.config.lineNumbers,
                matchBrackets: this.config.matchBrackets,
                theme: this.config.theme
            });
            this._waitEditor.resolve(this._editor);
            this.setValue(this.config.value);
            if (this._focus_await)
                this.focus();
        },
        _set_inner_size: function () {
            if (!this._editor || !this.$width)
                return;
            this._updateScrollSize();
            this._editor.scrollTo(0, 0);
        },
        _updateScrollSize: function () {
            var box = this._editor.getWrapperElement();
            var height = (this.$height || 0) + "px";
            box.style.height = height;
            box.style.width = (this.$width || 0) + "px";
            var scroll = this._editor.getScrollerElement();
            if (scroll.style.height != height) {
                scroll.style.height = height;
                this._editor.refresh();
            }
        },
        $setSize: function (x, y) {
            if (webix.ui.view.prototype.$setSize.call(this, x, y)) {
                this._set_inner_size();
            }
        },
        setValue: function (value) {
            if (!value && value !== 0)
                value = "";
            this.config.value = value;
            if (this._editor) {
                this._editor.setValue(value);
                if (!this.config.preserveUndoHistory)
                    this._editor.clearHistory();
                this._updateScrollSize();
            }
        },
        getValue: function () {
            return this._editor ? this._editor.getValue() : this.config.value;
        },
        focus: function () {
            this._focus_await = true;
            if (this._editor)
                this._editor.focus();
        },
        getEditor: function (waitEditor) {
            return waitEditor ? this._waitEditor : this._editor;
        },
        undo: function () {
            this._editor.undo();
        },
        redo: function () {
            this._editor.redo();
        },
        undoLength: function () {
            return this._editor.historySize().undo;
        }
    }, webix.ui.view);

    function fileNameSelectMask(input) {
        var extIndex = input.value.lastIndexOf(".");
        if (extIndex > -1)
            input.setSelectionRange(0, extIndex);
        else
            input.select();
    }
    function iterateAsync(arr, code, ctx) {
        ctx = ctx || { i: 0, cancel: false };
        if (ctx.i >= arr.length)
            return;
        return code(arr[ctx.i], ctx.i).then(function () {
            ctx.i += 1;
            if (!ctx.cancel)
                return iterateAsync(arr, code, ctx);
        });
    }
    function formatTemplate(size) {
        if (size >= 1000000000)
            return (size / 1000000000).toFixed(1) + " Gb";
        if (size >= 1000000)
            return (size / 1000000).toFixed(1) + " Mb";
        if (size >= 1000)
            return (size / 1000).toFixed(1) + " kb";
        return size + " b";
    }
    function ext(path) {
        if (!path)
            return "";
        var parts = path.split(".");
        if (parts.length < 2)
            return "";
        return parts[parts.length - 1];
    }
    function getLastSelected(files) {
        return files.length
            ? files[files.length - 1]
            : { type: "empty", value: "Nothing is selected" };
    }
    function safeName(str) {
        return str;
        str = str.replace(/[/\\:*?"<>|]/g, "").trim();
        while (str[0] === ".")
            str = str.substr(1).trim();
        return str;
    }
    function capitalize(str) {
        if (!str)
            return "";
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    var typeHash = {
        css: ["css", "less"],
        go: ["go"],
        htmlmixed: ["html", "xml", "svg"],
        javascript: ["js", "mjs", "json", "ts", "coffee"],
        markdown: ["md"],
        php: ["php", "phtml", "php3", "php4", "php5", "php7", "php-s", "pht", "phar"],
        python: ["py", "pyc", "pyd", "pyo", "pyw", "pyz"],
        sql: ["sql", "sqlite3", "sqlite", "db"],
        yaml: ["yaml", "yml"],
        shell: ["sh"],
    };
    var EditorView = (function (_super) {
        __extends(EditorView, _super);
        function EditorView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        EditorView.prototype.config = function () {
            var _this = this;
            this._ = this.app.getService("locale")._;
            this.Files = this.getParam("files");
            var tabbar = {
                view: "tabbar",
                localId: "tabbar",
                css: "webix_fmanager_editor_tabs",
                borderless: true,
                tabMinWidth: 170,
                moreTemplate: "<span class='webix_icon webix_tabbar_more fmi-format-list-bulleted'></span>",
                tooltip: function (obj) { return obj.id; },
                options: [],
                on: {
                    onChange: function (id) { return _this.OpenDoc(id); },
                    onBeforeTabClick: function (id, e) { return _this.TabAction(id, e); },
                },
                tabbarPopup: {
                    css: "webix_gantt_editor_popup",
                },
            };
            var skin = webix.skin.$active;
            var toolbar = {
                view: "toolbar",
                css: "webix_fmanager_editor_bar",
                padding: { left: 4, right: 4, top: 0, bottom: 0 },
                height: skin.toolbarHeight + !(skin.toolbarHeight % 2),
                cols: this.GetMobileControls(),
                //cols: webix.env.touch ? this.GetMobileControls() : [tabbar],
            };

            toolbar.cols.push(this.GetCloseAll(true));
            /*
            if (webix.env.touch) {
                toolbar.cols.push(this.GetCloseAll(true));
            }
            else {
                toolbar.cols.push({ width: 12 }, this.GetCloseAll());
            }
            */
            return {
                type: "wide",
                rows: [
                    toolbar,
                    {
                        view: "codemirror-editor",
                        localId: "editor",
                        theme: webix.skin.$name === "contrast" ? "monokai" : "default",
                    },
                ],
            };
        };
        EditorView.prototype.GetMobileControls = function () {
            var _this = this;
            return [
                {
                    view: "button",
                    localId: "saveBtn",
                    type: "icon",
                    label: this._("Save"),
                    icon: "wxi-check",
                    css: "webix_primary webix_fmanager_editor_save",
                    width: 140,
                    click: function () { return _this.Save(); },
                },
                {
                    view: "label",
                    label: " ",
                    localId: "name",
                    css: "webix_fmanager_editor_name",
                },
            ];
        };
        EditorView.prototype.GetCloseAll = function (mobile) {
            var _this = this;
            return {
                view: "icon",
                icon: mobile ? "wxi-close" : "fmi-exit-to-app",
                click: function () { return _this.ConfirmAll(); },
                tooltip: this._("Close the editor") + " (Esc)",
            };
        };
        EditorView.prototype.init = function () {
            var _this = this;
            var editor = this.$$("editor");
            webix.extend(editor, webix.ProgressBar);
            editor.showProgress({
                type: "top",
            });

            var file = this.Files[0];
            this.$$("name").setValue(this.GetFileLabel(file));

            this._oldValue = {};
            this._changed = {};
            this._buffers = {};
            editor.getEditor(true).then(function (editorObj) {
                iterateAsync(_this.Files, function (file) {
                    return _this.app
                        .getService("operations")
                        .read(file.id)
                        .then(function (text) { return _this.AddDoc(file, text); });
                })
                    .then(function () {
                    /*
                        if (!webix.env.touch) {
                        var tabbar = _this.$$("tabbar");
                        for (var i = 0; i < _this.Files.length; ++i) {
                            _this.AddTab(tabbar, _this.Files[i]);
                        }
                        tabbar.setValue(tabbar.config.options[0].id);
                    }
                    */
                    _this.OpenDoc(_this.Files[0].id);
                })
                    .finally(function () {
                    editor.hideProgress();
                });
                _this.HandleChanges(editorObj);
            });
            var ctrlKey = webix.env.isMac ? "COMMAND" : "CTRL";
            this.on(handler(), ctrlKey + " + S", function (v, e) {
                _this.Save();
                webix.html.preventEvent(e);
            });
            this.on(handler(), ctrlKey + " + Shift + S", function () { return _this.SaveAll(); });
            this.on(handler(), "ESC", function () { return _this.ConfirmAll(); });
        };
        EditorView.prototype.HandleChanges = function (editor) {
            var _this = this;
            editor.on("changes", function () {
                _this.TextChanged(_this.GetActiveFile());
            });
        };
        EditorView.prototype.AddDoc = function (file, text) {
            this._oldValue[file.id] = text;
            this._buffers[file.id] = CodeMirror.Doc(text, this.GetFileType(file));
        };
        EditorView.prototype.GetFileLabel = function (file, short) {
            if (file.$ext) {
                return "<div class=\"filename\">" + this.ClipName(file.value) + "</div><span class=\"extension\">." + file.$ext + "</span>";
                //return "<div class=\"filename\">" + this.ClipName(short ? file.value : file.id) + "</div><span class=\"extension\">." + file.$ext + "</span>";
            } else {
                return "<div class=\"filename\">" + file.value + "</div><span class=\"extension\"></span>";
            }
        };
        EditorView.prototype.ClipName = function (path) {
            if (!path)
                return "";
            var parts = path.split(".");
            return parts.slice(0, parts.length - 1).join(".");
        };
        EditorView.prototype.AddTab = function (tabbar, file) {
            var icon = "<span class=\"webix_fmanager_tab_action webix_icon wxi-close\" webix_tooltip=\"" + this._("Close this file") + "\"></span>";
            var tabContent = "<div class=\"tab_content\">" + this.GetFileLabel(file, true) + icon + "</div>";
            var width = webix.html.getTextSize(file.value, "webix_item_tab").width + 90;
            tabbar.addOption({
                id: file.id,
                value: tabContent,
                css: "webix_fmanager_editor_tab",
                width: width > 250 ? 250 : width,
            });
        };
        EditorView.prototype.OpenDoc = function (name) {
            var _this = this;
            this.$$("editor")
                .getEditor(true)
                .then(function (editor) {
                editor.swapDoc(_this._buffers[name]);
                editor.focus();
            });
        };
        EditorView.prototype.Back = function () {
            this.show("/top", { params: { state: this.getParam("state") } });
        };
        EditorView.prototype.ConfirmAll = function () {
            var _this = this;
            if (this.CheckChanges())
                webix
                    .confirm({
                    text: this._("Are you sure you want to exit without saving?"),
                })
                    .then(function () { return _this.Back(); });
            else
                this.Back();
        };
        EditorView.prototype.TabAction = function (id, e) {
            if (e) {
                var classes = e.target.className;
                var actionIcon = classes.indexOf("webix_icon") !== -1 && id == this.$$("tabbar").getValue();
                if (actionIcon) {
                    if (classes.indexOf("close") !== -1) {
                        this.CloseTab(id);
                    }
                    else if (classes.indexOf("circle") !== -1) {
                        this.ConfirmOne(id);
                    }
                }
                return !actionIcon;
            }
            return true;
        };
        EditorView.prototype.ConfirmOne = function (id) {
            var _this = this;
            webix
                .confirm({
                text: this._("Save before closing?"),
            })
                .then(function () {
                _this.Save(id).then(function () { return _this.CloseTab(id); });
            })
                .catch(function () {
                _this.CloseTab(id);
            });
        };
        EditorView.prototype.CheckChanges = function () {
            for (var f in this._changed) {
                if (this._changed[f])
                    return true;
            }
            return false;
        };
        EditorView.prototype.SaveAll = function () {
            var _this = this;
            var files = this.Files.filter(function (f) { return _this._changed[f.id]; });
            if (files.length) {
                iterateAsync(files, function (file) {
                    return _this.WriteFileContent(file.id);
                }).then(function () {
                    _this.ChangeTextState(false);
                });
            }
        };
        EditorView.prototype.Save = function (id) {
            var _this = this;
            if (!id)
                id = this.GetActiveFile();
            if (this._changed[id]) {
                return this.WriteFileContent(id).then(function () {
                    _this.ChangeTextState(false, id);
                });
            }
            return webix.promise.resolve();
        };
        EditorView.prototype.WriteFileContent = function (id) {
            var _this = this;
            var content = this._buffers[id].getValue();
            return this.app
                .getService("operations")
                .write(id, content)
                .then(function () {
                _this._oldValue[id] = content;
            });
        };
        EditorView.prototype.GetActiveFile = function () {
            return this.Files[0].id;
            //return webix.env.touch ? this.Files[0].id : this.$$("tabbar").getValue();
        };
        EditorView.prototype.GetFileType = function (file) {
            if (file.value === "Dockerfile")
                return "dockerfile";
            if (file.$ext) {
                for (var type in typeHash) {
                    if (typeHash[type].indexOf(file.$ext) !== -1) {
                        return type;
                    }
                }
            }
            return "htmlmixed";
        };
        EditorView.prototype.TextChanged = function (file) {
            var isEqual = this._buffers[file] == this._oldValue[file];
            this.ChangeTextState(!isEqual, file);
        };
        EditorView.prototype.ChangeTextState = function (state, file) {
            if (file && state === !!this._changed[file])
                return;
            if (file)
                this._changed[file] = state;
            else
                this._changed = {};
            this.ChangeButtonState(state);
            /*
            if (webix.env.touch) {
                this.ChangeButtonState(state);
            }
            else {
                this.ChangeTabsState(state, file);
            }
            */
        };
        EditorView.prototype.ChangeButtonState = function (state) {
            var button = this.$$("saveBtn");
            button.config.icon = state ? "webix_fmanager_icon fmi-circle" : "wxi-check";
            button.refresh();
        };
        EditorView.prototype.ChangeTabsState = function (state, file) {
            var tabbar = this.$$("tabbar");
            if (file) {
                var tab = tabbar.getOption(file);
                this.ChangeTabState(tab, state);
            }
            else {
                var tabs = tabbar.config.options;
                for (var i = 0; i < tabs.length; ++i) {
                    this.ChangeTabState(tabs[i], state);
                }
            }
            tabbar.refresh();
        };
        EditorView.prototype.ChangeTabState = function (tab, state) {
            tab.value = tab.value.replace(state ? "wxi-close" : "webix_fmanager_icon fmi-circle", state ? "webix_fmanager_icon fmi-circle" : "wxi-close");
        };
        EditorView.prototype.CloseTab = function (id) {
            var tabbar = this.$$("tabbar");
            tabbar.removeOption(id);
            if (!tabbar.getValue()) {
                return this.Back();
            }
            delete this._buffers[id];
            delete this._changed[id];
            delete this._oldValue[id];
        };
        return EditorView;
    }(JetView));

    function prompt(config) {
        var result = new webix.promise.defer();
        var p = webix.ui({
            view: "window",
            css: "webix_fmanager_prompt",
            modal: true,
            move: true,
            head: {
                view: "toolbar",
                padding: { left: 12, right: 4 },
                borderless: true,
                elements: [
                    { view: "label", label: config.text },
                    {
                        view: "icon",
                        icon: "wxi-close",
                        hotkey: "esc",
                        click: function () {
                            result.reject("prompt cancelled");
                            p.close();
                        },
                    },
                ],
            },
            position: config.master ? "" : "center",
            body: {
                view: "form",
                padding: { top: 0, left: 12, right: 12, bottom: 12 },
                rows: [
                    {
                        margin: 10,
                        cols: [
                            {
                                view: "text",
                                name: "name",
                                value: config.value,
                                width: 230,
                                validate: safeName,
                                css: "webix_fmanager_prompt_input",
                            },
                            {
                                view: "button",
                                value: config.button,
                                css: "webix_primary",
                                width: 100,
                                hotkey: "enter",
                                click: function () {
                                    var popup = this.getTopParentView();
                                    var form = popup.getBody();
                                    if (form.validate()) {
                                        var newname = form.getValues().name;
                                        result.resolve(newname);
                                        popup.close();
                                    }
                                    else {
                                        webix.UIManager.setFocus(form);
                                    }
                                },
                            },
                        ],
                    },
                ],
            },
            on: {
                onShow: function () {
                    var input = this.getBody().elements.name.getInputNode();
                    input.focus();
                    if (config.selectMask)
                        config.selectMask(input);
                    else
                        input.select();
                },
            },
        });
        var position = config.master ? { x: 50 } : null;
        webix.delay(function () { return p.show(config.master, position); });
        return result;
    }

    var AddNewMenuView = (function (_super) {
        __extends(AddNewMenuView, _super);
        function AddNewMenuView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        AddNewMenuView.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            return {
                view: "popup",
                width: 198,
                body: {
                    view: "menu",
                    autoheight: true,
                    layout: "y",
                    css: "webix_fmanager_add_new_menu",
                    data: [
                        {
                            id: "makefile",
                            value: _("Add new file"),
                            icon: "webix_fmanager_icon fmi-file-plus-outline",
                        },
                        {
                            id: "makedir",
                            value: _("Add new folder"),
                            icon: "webix_fmanager_icon fmi-folder-plus-outline",
                        },
                        {
                            id: "upload",
                            value: _("Upload file"),
                            icon: "webix_fmanager_icon fmi-file-upload-outline",
                        },
                    ],
                    on: {
                        onMenuItemClick: function (id) {
                            if (id === "makefile" || id === "makedir") {
                                var _1 = _this.app.getService("locale")._;
                                prompt({
                                    text: _1("Enter a new name"),
                                    button: _1("Add"),
                                    selectMask: fileNameSelectMask,
                                    value: "new-" + (id === "makefile" ? "file.txt" : "folder"),
                                }).then(function (name) {
                                    _this.app.callEvent("app:action", [id, safeName(name)]);
                                });
                            }
                            else {
                                _this.app.callEvent("app:action", [id]);
                            }
                            _this.getRoot().hide();
                        },
                    },
                },
            };
        };
        AddNewMenuView.prototype.Show = function (target) {
            this.getRoot().show(target, { x: 20 });
        };
        return AddNewMenuView;
    }(JetView));

    var FoldersView = (function (_super) {
        __extends(FoldersView, _super);
        function FoldersView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        FoldersView.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            var button = {
                view: "button",
                value: _("Add New"),
                inputWidth: 210,
                css: "webix_primary",
                align: "center",
                click: function () {
                    this.$scope.Menu.Show(this.$view);
                },
            };
            var navbar = {
                view: "tree",
                localId: "tree",
                css: "webix_fmanager_tree",
                select: true,
                drag: "target",
                type: {
                    template: function (o, common) {
                        return "" + common.icon(o) + common.folder(o) + " <span>" + (o.$level === 1 ? _(o.value) : o.value) + "</span>";
                    },
                    folder: function (o) { return "<div class='webix_icon wxi-" + (o.icon || "folder") + "'></div>"; },
                },
                borderless: true,
                on: {
                    onBeforeDrop: function (ctx) { return _this.MoveFiles(ctx.source, ctx.target); },
                    onBeforeContextMenu: function (id) {
                        if (id.substr(0, 2) !== "..")
                            _this.Tree.select(id);
                        else
                            return false;
                    },
                },
            };
            var FSStats = {
                localId: "fs:stats",
                borderless: true,
                height: 68,
                css: "webix_fmanager_fsstats",
                template: function (obj) {
                    var used = Math.floor((obj.used / obj.total) * 100);
                    var activeSkinAccentColor = webix.skin.$active.timelineColor;
                    var svg = "<svg width=\"100%\" height=\"20px\">\n<rect y=\"1\" rx=\"4\" width=\"100%\" height=\"8\" style=\"fill:#DFE2E6;\" />\n<rect y=\"1\" rx=\"4\" width=\"" + (used ||
                        0) + "%\" height=\"8\" style=\"fill:" + activeSkinAccentColor + ";\" /></svg>";
                    var label = "<div class=\"webix_fmanager_fsstats_label\">" + formatTemplate(obj.used || 0) + " " + _("of") + " " + formatTemplate(obj.total || 0) + " " + _("used") + "</div>";
                    return svg + label;
                },
            };
            var navpanel = {
                view: "proxy",
                body: {
                    rows: [button, navbar],
                    padding: { top: 8, bottom: 4 },
                },
            };
            return navpanel;
        };
        FoldersView.prototype.init = function () {
            var _this = this;
            this.State = this.getParam("state");
            this.Tree = this.$$("tree");
            this.Ready = this.app
                .getService("local")
                .folders()
                .then(function (h) {
                _this.Tree.sync(h);
                _this.Subscribe();
                _this.GetFsStats();
            });
            this.Menu = this.ui(AddNewMenuView);
            this.ContextMenu = this.ui(new (this.app.dynamic(ContextMenuView))(this.app, {
                compact: false,
                tree: true,
            }));
            this.ContextMenu.AttachTo(this.Tree, function (e) {
                var id = _this.Tree.locate(e);
                return id ? [_this.Tree.getItem(id)] : null;
            });
            this.on(this.app, "reload:fs:stats", function () { return _this.GetFsStats(true); });
        };
        FoldersView.prototype.Subscribe = function () {
            var _this = this;
            this.Tree.attachEvent("onAfterSelect", function () {
                var v = _this.Tree.getSelectedId();
                if (v.substr(0, 2) == "..") {
                    _this.State.$batch({
                        source: v.slice(3),
                        path: "/",
                    });
                }
                else {
                    _this.State.$batch({
                        source: _this.GetRootId(v).slice(3),
                        path: v,
                    });
                }
            });
            this.on(this.State.$changes, "path", function (v) {
                _this.State.path = v;
                if (_this.Tree.exists(v)) {
                    _this.Tree.select(v);
                    var parent = _this.Tree.getParentId(v);
                    if (parent)
                        _this.Tree.open(parent);
                    _this.Tree.showItem(v);
                }
                else
                    _this.Tree.select("../" + _this.State.source);
            });
        };
        FoldersView.prototype.GetFsStats = function (force) {
            var _this = this;
            /*
            this.app
                .getService("backend")
                .getInfo(force)
                .then(function (data) {
                _this.$$("fs:stats").setValues(data.stats);
            });
            */
        };
        FoldersView.prototype.MoveFiles = function (source, target) {
            if (target === "../files")
                target = "/";
            this.app.getService("operations").move(source, target);
            return false;
        };
        FoldersView.prototype.GetRootId = function (path) {
            var root;
            while (path) {
                root = path;
                path = this.Tree.getParentId(path);
            }
            return root;
        };
        return FoldersView;
    }(JetView));

    var GridView = (function (_super) {
        __extends(GridView, _super);
        function GridView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        GridView.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            var compact = this.getParam("compact", true);
            var grid = {
                view: "datatable",
                localId: "table",
                css: "webix_noselect webix_header_border webix_fmanager_filelist",
                select: "row",
                multiselect: true,
                drag: true,
                resizeColumn: { headerOnly: true },
                sort: "multi",
                type: {
                    backIcon: function () { return backIcon; },
                    backLabel: function () { return _("back to parent folder"); },
                },
                on: {
                    onItemDblClick: function () { return _this.Activate(_this.GetSelection()); },
                    onEnter: function () { return _this.Activate(_this.GetSelection()); },
                    onBeforeDrag: function (ctx) { return _this.DragMarker(ctx); },
                    onBeforeDrop: function (ctx) { return _this.MoveFiles(ctx.source, ctx.target);},
                    "data->onStoreLoad": function () {
                        _this.WTable.markSorting();
                    },
                },
                onClick: {
                    webix_ss_center_scroll: function () { return _this.EmptyClick(); },
                    webix_column: function () { return false; },
                },
                onContext: {
                    webix_ss_center_scroll: function (e, id) {
                        if (!id)
                            _this.EmptyClick();
                    },
                },
                columns: [
                    {
                        id: "value",
                        header: "",
                        template: function (obj) { return _this.NameTemplate(obj); },
                        sort: sort("value"),
                        fillspace: true,
                    },
                    {
                        id: "owner",
                        header: _("Owner"),
                        template: function (obj) {
                            return obj.owner;
                        },
                        sort: sort("owner"),
                        width: 170,
                    },
                    {
                        id: "permissions",
                        header: _("Permissions"),
                        template: function (obj) {
                            return obj.permissions;
                        },
                        sort: sort("permissions"),
                        width: 120,
                    },
                    {
                        id: "size",
                        header: _("Size"),
                        template: function (obj) {
                            return obj.type !== "folder" ? formatTemplate(obj.size) : "";
                        },
                        sort: sort("size"),
                    },
                    {
                        id: "date",
                        header: _("Date"),
                        sort: sort("date"),
                        format: function (date) {
                            if (date instanceof Date && !isNaN(date)) {
                                var month = date.toLocaleString('default', { month: 'short' });
                                var dateStr =
                                    (month + " " + date.getDate() + ", ") +
                                    ("00" + date.getHours()).slice(-2) + ":" +
                                    ("00" + date.getMinutes()).slice(-2) + ":" +
                                    ("00" + date.getSeconds()).slice(-2);
                                return dateStr;
                                //return webix.i18n.longDateFormatStr(date);
                            } else {
                                return "";
                            }
                        },
                        width: 160,
                    },
                ],
            };
            if (compact) {
                grid.columns.splice(1, 2);
            }
            return grid;
        };
        GridView.prototype.init = function () {
            var _this = this;
            this.WTable = this.$$("table");
            _super.prototype.init.call(this);
            this.on(this.State.$changes, "isActive", function (v) {
                if (!v) {
                    _this.Menu.Hide();
                    _this._Track = false;
                    _this.WTable.unselect();
                    _this._Track = true;
                }
                else {
                    _this.SelectActive();
                    webix.delay(function () { return webix.UIManager.setFocus(_this.WTable); });
                }
            });
            this.on(handler(this.getRoot()), "TAB", function () {
                if (_this.getParam("trackActive", true))
                    _this.State.isActive = false;
            });
        };
        GridView.prototype.RenderData = function (data) {
            this.WTable.sync(data);
        };
        GridView.prototype.NameTemplate = function (obj) {
            return obj.type === "folder"
                ? folderIcon + obj.value
                : this.Icon(obj) + "<span class=\"file-name\">" + obj.value + "</span>";
        };
        return GridView;
    }(DataViewBase));
    function sort(by) {
        return function (a, b) {
            if (a.value === ".." ||
                b.value === ".." ||
                (a.type === "folder" && b.type !== "folder") ||
                (b.type === "folder" && a.type !== "folder"))
                return 0;
            if (by === "value")
                return a.value.localeCompare(b.value, undefined, {
                    ignorePunctuation: true,
                    numeric: true,
                });
            return a[by] < b[by] ? -1 : a[by] > b[by] ? 1 : 0;
        };
    }

    var PreviewPopupView = (function (_super) {
        __extends(PreviewPopupView, _super);
        function PreviewPopupView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        PreviewPopupView.prototype.config = function () {
            return {
                view: "window",
                head: false,
                fullscreen: true,
                body: { $subview: true, branch: true, name: "preview" },
            };
        };
        PreviewPopupView.prototype.IsVisible = function () {
            return this.getRoot().isVisible();
        };
        PreviewPopupView.prototype.Show = function (params) {
            this.show("preview", {
                target: "preview",
                params: { state: params.state, compact: true },
            });
            this.getRoot().show();
        };
        PreviewPopupView.prototype.Hide = function () {
            this.show("_blank", {
                target: "preview",
            });
            this.getRoot().hide();
        };
        return PreviewPopupView;
    }(JetView));

    var SideTreeView = (function (_super) {
        __extends(SideTreeView, _super);
        function SideTreeView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        SideTreeView.prototype.config = function () {
            return {
                view: "sidemenu",
                width: 300,
                state: function (state) {
                    var toolbarHeight = webix.skin.$active.toolbarHeight + 14;
                    state.top = toolbarHeight;
                    state.height -= toolbarHeight;
                },
                body: FoldersView,
            };
        };
        SideTreeView.prototype.IsVisible = function () {
            return this.getRoot().isVisible();
        };
        SideTreeView.prototype.Show = function () {
            this.getRoot().show();
        };
        return SideTreeView;
    }(JetView));

    var DoublePanelView = (function (_super) {
        __extends(DoublePanelView, _super);
        function DoublePanelView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        DoublePanelView.prototype.config = function () {
            var panels = {
                type: "wide",
                cols: [
                    { $subview: true, branch: true, name: "left" },
                    { view: "resizer" },
                    { $subview: true, branch: true, name: "right" },
                ],
            };
            return panels;
        };
        DoublePanelView.prototype.init = function () {
            this.State = this.getParam("state");
            var left = createState({
                selectedItem: [].concat(this.State.selectedItem),
                path: this.State.path,
                source: this.GetSource("left"),
                mode: "list",
                isActive: true,
            });
            left.selectedItem.$noSelect = this.State.selectedItem.$noSelect;
            var right = createState({
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
        };
        DoublePanelView.prototype._TrackChanges = function (state, next) {
            var _this = this;
            this.on(state.$changes, "path", function (v) {
                if (state.isActive)
                    _this.State.path = v;
            });
            this.on(state.$changes, "source", function (v) { return (_this.State.source = v); });
            this.on(state.$changes, "selectedItem", function (v) {
                if (state.isActive)
                    _this.State.selectedItem = v;
            });
            this.on(state.$changes, "isActive", function (v) {
                if (v)
                    _this.State.$batch({
                        path: state.path,
                        source: state.source,
                    });
                next.isActive = !v;
            });
            this.on(this.app, "pathChanged", function (opath, npath) {
                if (!state.isActive &&
                    (state.path === opath || state.path.indexOf(opath + "/") === 0)) {
                    state.path = state.path.replace(opath, npath);
                }
            });
        };
        DoublePanelView.prototype.GetSource = function () {
            return this.State.source;
        };
        return DoublePanelView;
    }(JetView));

    var PanelSearchView = (function (_super) {
        __extends(PanelSearchView, _super);
        function PanelSearchView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        PanelSearchView.prototype.config = function () {
            var _this = this;
            this.State = this.getParam("state");
            var _ = this.app.getService("locale")._;
            var back = {
                view: "icon",
                icon: "wxi-angle-left",
                click: function () {
                    _this.State.$batch({
                        search: "",
                        searchStats: null,
                    });
                },
            };
            var toolbar = {
                view: "toolbar",
                cols: [
                    back,
                    {
                        localId: "header",
                        type: "header",
                        borderless: true,
                        css: "webix_fmanager_path",
                        template: function (obj) {
                            var text = _("Search results in");
                            var root = _this.app.getService("backend").getRootName();
                            var path = "";
                            if (obj && obj.path && obj.path !== "/") {
                                path = webix.template.escape(obj.path);
                            }
                            return text + " " + root + path;
                        },
                    },
                    {},
                ],
            };
            return {
                rows: [toolbar, { $subview: true, params: { state: this.State } }],
            };
        };
        PanelSearchView.prototype.init = function () {
            this.$$("header").setValues({ path: this.State.path });
        };
        PanelSearchView.prototype.ready = function () {
            var _this = this;
            this.on(this.State.$changes, "search", function (v) {
                v = v.trim();
                if (v) {
                    _this.State.selectedItem = [];
                    _this.getSubView().LoadData(_this.State.path, v);
                }
            });
        };
        return PanelSearchView;
    }(JetView));

    var PanelView = (function (_super) {
        __extends(PanelView, _super);
        function PanelView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        PanelView.prototype.config = function () {
            var _this = this;
            var path = {
                view: "template",
                localId: "path",
                type: "header",
                borderless: true,
                css: "webix_fmanager_path",
                template: function (obj) { return _this.RenderPath(obj); },
                onClick: {
                    webix_fmanager_path_chunk: function (e) { return _this.ChangePath(e); },
                },
            };
            var refresh = {
                view: "icon",
                icon: "wxi-sync",
                css: "webix_fmanager_spec_icon",
                tooltip: "Refresh",
                click: function () {
                    _this.app.getService("local").refresh(_this.State.path);
                },
            };
            var toolbar = {
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
        };
        PanelView.prototype.init = function () {
            var _this = this;
            this.State = this.getParam("state");
            this.on(this.State.$changes, "path", function (v) { return _this.ProcessPath(v); });
            var uapi = this.app.getService("upload").getUploader();
            uapi.addDropZone(this.getRoot().$view);
            this.on(uapi, "onBeforeFileDrop", function (files, e) {
                if (_this.getRoot().$view.contains(e.target))
                    uapi.config.tempUrlData = { id: _this.State.path };
            });
        };
        PanelView.prototype.ProcessPath = function (v) {
            var _this = this;
            this.app
                .getService("local")
                .folders()
                .then(function (dirs) {
                    //stefan wie
                    //console.log(dirs.data.branch);
                var path = ["/"];
                if (v !== "/") {
                    var p = v.split("/");
                    for (var i = 1, id = ""; i < p.length; ++i) {
                        id += "/" + p[i];
                        path.push(dirs.getItem(id).value);
                    }
                }
                _this.$$("path").setValues({ path: path });
            });
        };
        PanelView.prototype.RenderPath = function (obj) {
            if (obj.path && obj.path.length) {
                var icon_1 = "<span class='webix_icon wxi-angle-right'></span>";
                var rootName_1 = this.app.getService("backend").getRootName();
                var htmlPath_1 = "";
                obj.path.forEach(function (chunk, index) {
                    htmlPath_1 += "<span class=\"webix_fmanager_path_chunk\" data-path=\"" + index + "\">" + (index ? chunk : rootName_1) + "</span>";
                    if (index < obj.path.length - 1)
                        htmlPath_1 += icon_1;
                });
                return htmlPath_1;
            }
            return "";
        };
        PanelView.prototype.ChangePath = function (e) {
            var chunkInd = e.target.getAttribute("data-path") * 1;
            var path = this.State.path.split("/");
            path.splice(chunkInd + 1, path.length - 1);
            var newPath = path.join("/") || "/";
            this.State.path = newPath;
        };
        return PanelView;
    }(JetView));

    var InfoPreview = (function (_super) {
        __extends(InfoPreview, _super);
        function InfoPreview() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        InfoPreview.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            var dateTimeFormat = webix.Date.dateToStr("%M %j, %Y&nbsp;&nbsp;&nbsp;&nbsp;%H:%i:%s");
            var mainInfo = {
                css: "webix_fmanager_preview_info",
                localId: "info",
                borderless: true,
                autoheight: true,
                template: function (obj) {
                    if (!obj.id)
                        return "";
                    var _ = _this.app.getService("locale")._;
                    var keys = (obj.type === "folder"
                        ? ["Type", "Date"]
                        : ["Type", "Size", "Date"]).map(function (k) { return "<div class='key_value_cell''>" + _(k) + "</div>"; });
                    var keyCol = "<div class='key_col'>" + keys.join("") + "</div>";
                    var values = (obj.type === "folder"
                        ? [capitalize(_(obj.type)), dateTimeFormat(obj.date)]
                        : [
                            capitalize(_(obj.type)),
                            formatTemplate(obj.size),
                            dateTimeFormat(obj.date),
                        ]).map(function (v) { return "<div class='key_value_cell'>" + v + "</div>"; });
                    var valueCol = "<div class=\"value_col\">" + values.join("") + "</div>";
                    return "<div>" + keyCol + valueCol + "</div>";
                },
            };
            var extraInfo = {
                css: "webix_fmanager_preview_info extra",
                localId: "extra:info",
                hidden: true,
                autoheight: true,
                template: function (obj) {
                    var tags = Object.keys(obj);
                    var keyCol = "<div class='key_col'>";
                    for (var i = 0; i < tags.length; ++i) {
                        keyCol += "<div class='key_value_cell key'>" + tags[i] + "</div>";
                    }
                    keyCol += "</div>";
                    var valueCol = "<div class='value_col'>";
                    for (var val in obj) {
                        var value = obj[val].trim();
                        valueCol += "<div class='key_value_cell'>" + (value && value != "0"
                            ? value
                            : "<span class='webix_fmanager_id3tags-unknown'>Unknown</span>") + "</div>";
                    }
                    valueCol += "</div>";
                    return "<div><div class=\"webix_fmanager_info_header\">\n<span class=\"webix_fmanager_icon fmi-information-outline\"></span>\n<span class=\"webix_fmanager_info_title\">Extra info</span>\n</div>" + keyCol + valueCol + "</div>";
                },
            };
            var counter = {
                localId: "search:counter",
                css: "webix_fmanager_preview_info",
                height: 104,
                borderless: true,
                template: function (o) {
                    var keys = ["Folders", "Files"].map(function (k) { return "<div class='key_value_cell''>" + _(k) + "</div>"; });
                    var keyCol = "<div class='key_col search'>" + keys.join("") + "</div>";
                    var values = [o.folders, o.files].map(function (v) { return "<div class='key_value_cell'>" + v + "</div>"; });
                    var valueCol = "<div class=\"value_col search\">" + values.join("") + "</div>";
                    return "<div>" + keyCol + valueCol + "</div>";
                },
            };
            var infoTabs = {
                localId: "info:tabs",
                view: "tabview",
                css: "webix_fmanager_info_tab",
                cells: [
                    {
                        header: _("Information"),
                        body: {
                            padding: 14,
                            margin: 14,
                            rows: [mainInfo, extraInfo, {}],
                        },
                    },
                    {
                        header: _("Search results"),
                        body: {
                            padding: 14,
                            margin: 14,
                            rows: [counter, {}],
                        },
                    },
                ],
            };
            return infoTabs;
        };
        InfoPreview.prototype.init = function () {
            var _this = this;
            this.Tabview = this.$$("info:tabs");
            this.State = this.getParam("state");
            this.on(this.State.$changes, "selectedItem", function (v) {
                _this.ShowInfo(getLastSelected(v));
            });
            this.on(this.State.$changes, "searchStats", function (v) {
                if (v) {
                    _this.$$("search:counter").setValues(v);
                }
                else {
                    if (!_this.State.selectedItem.length)
                        _this.Tabview.hide();
                }
            });
        };
        InfoPreview.prototype.ShowInfo = function (v) {
            var _this = this;
            var tabbar = this.Tabview.getTabbar();
            var infoId = tabbar.config.options[0].id;
            var counterId = tabbar.config.options[1].id;
            if (v.type !== "empty") {
                this.SetInfo(this.$$("info"), v);
                this.SwitchTabs(tabbar, counterId, infoId);
                var extraInfo_1 = this.$$("extra:info");
                var meta = this.app.getService("backend").getMeta(v);
                if (!meta)
                    extraInfo_1.hide();
                else
                    meta
                        .then(function (data) { return _this.SetExtraInfo(extraInfo_1, data); })
                        .catch(function () { return extraInfo_1.hide(); });
            }
            else {
                if (!this.State.search)
                    this.Tabview.hide();
                else
                    this.SwitchTabs(tabbar, infoId, counterId);
            }
        };
        InfoPreview.prototype.SetExtraInfo = function (view, data) {
            if (!_isEmpty(data)) {
                this.SetInfo(view, data);
            }
            else
                view.hide();
        };
        InfoPreview.prototype.SetInfo = function (view, data) {
            view.setValues(data);
            view.show();
        };
        InfoPreview.prototype.SwitchTabs = function (tab, hide, show) {
            tab.hideOption(hide);
            tab.setValue(show);
            tab.showOption(show);
            this.Tabview.show();
        };
        return InfoPreview;
    }(JetView));
    function _isEmpty(obj) {
        for (var i in obj) {
            if (typeof obj[i] === "object")
                return _isEmpty(obj[i]);
            else if (obj[i] && obj[i] !== "0")
                return false;
        }
        return true;
    }

    var PreviewView = (function (_super) {
        __extends(PreviewView, _super);
        function PreviewView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        PreviewView.prototype.config = function () {
            var _this = this;
            var _ = this.app.getService("locale")._;
            var compact = this.getParam("compact");
            var skin = webix.skin.$active;
            var toolbar = {
                view: "toolbar",
                localId: "toolbar",
                height: skin.toolbarHeight + 2,
                padding: { right: 6 },
                elements: [
                    {
                        view: "label",
                        localId: "filename:label",
                        css: "webix_fmanager_preview_name",
                    },
                    {
                        view: "icon",
                        icon: "wxi-download",
                        css: "webix_fmanager_spec_icon",
                        localId: "download",
                        tooltip: _("Download file"),
                        click: function () {
                            _this.app.callEvent("app:action", ["download", [_this.FileInfo]]);
                        },
                    },
                    {
                        view: "icon",
                        icon: "wxi-close",
                        hidden: !compact,
                        click: function () { return _this.app.callEvent("app:action", ["toggle-preview"]); },
                    },
                ],
            };
            var preview = {
                view: "proxy",
                minHeight: 413,
                borderless: true,
                body: {
                    $subview: true,
                    name: "preview",
                },
            };
            return {
                margin: 0.1,
                rows: [
                    toolbar,
                    {
                        view: "scrollview",
                        borderless: true,
                        body: {
                            type: "wide",
                            margin: 10,
                            rows: [preview, InfoPreview],
                        },
                    },
                ],
            };
        };
        PreviewView.prototype.init = function () {
            var _this = this;
            this.on(this.getParam("state").$changes, "selectedItem", function (v) {
                var lastSelected = getLastSelected(v);
                _this.ShowInfo(lastSelected);
                _this.FileInfo = lastSelected;
                var downloadIcon = _this.$$("download");
                if (lastSelected.type === "folder") {
                    downloadIcon.hide();
                }
                else {
                    downloadIcon.show();
                }
            });
        };
        PreviewView.prototype.ShowInfo = function (v) {
            var previewUrl = "preview.template";
            var player = this.app.config.player;
            if (player && (v.type === "audio" || v.type === "video")) {
                previewUrl = "preview.media";
            }
            if (v.type !== "empty") {
                this.$$("filename:label").setValue(v.value);
                this.$$("toolbar").show();
            }
            else {
                this.$$("toolbar").hide();
            }
            this.show(previewUrl, {
                target: "preview",
                params: {
                    info: v,
                },
            });
        };
        return PreviewView;
    }(JetView));

    webix.protoUI({
        name: "plyr-player",
        defaults: {
            config: {},
            source: {}
        },
        $init: function () {
            this.$view.innerHTML = '<div class="webix_player_parent"><audio></audio></div>';
            this._container = this.$view.firstChild.firstChild;
            this.$view.style.overflow = "visible";
            this._waitView = webix.promise.defer();
            this.$ready.push(this.render);
        },
        getPlayer: function (wait) {
            return wait ? this._waitView : this._player;
        },
        render: function () {
            if (this.config.cdn === false || window.Plyr) {
                this._initPlyr();
                return;
            }
            var cdn = this.config.cdn ? this.config.cdn : "https://cdn.plyr.io/3.5.10";
            webix.require([
                cdn + "/plyr.js",
                cdn + "/plyr.css"
            ])
                .then(webix.bind(this._initPlyr, this))
                .catch(function (e) {
                console.log(e);
            });
        },
        _initPlyr: function () {
            if (this.$view) {
                var options = webix.extend({}, this.config.config);
                this._player = new Plyr(this._container, options);
                this._player.elements.container.setAttribute("tabindex", "-1");
                this.attachEvent("onDestroy", function () {
                    if (this._player) {
                        this._player.destroy();
                    }
                });
                this._waitView.resolve(this._player);
                this._player.on("canplay", webix.bind(function () {
                    if (this._player.media) {
                        this._player.media.setAttribute("tabindex", "-1");
                        this._normalizeRatio();
                    }
                }, this));
                this._player.on("ready", webix.bind(function () {
                    if (this.$view) {
                        this.$view.querySelector(".plyr--full-ui").style["min-width"] = "0px";
                        this._normalizeRatio();
                    }
                }, this));
            }
        },
        $setSize: function (x, y) {
            this.$view.firstChild.style.width = x + "px";
            this.$view.firstChild.style.height = (y - 2) + "px";
            if (this._player)
                this._normalizeRatio(x, y);
        },
        source_setter: function (value) {
            this._waitView.then(function (player) {
                if (value)
                    player.source = value;
            });
        },
        getPlyr: function (wait) {
            return wait ? this._waitView : this._player;
        },
        _gcdRatio: function (x, y) {
            x = Math.abs(x);
            y = Math.abs(y);
            while (y) {
                var t = y;
                y = x % y;
                x = t;
            }
            return x;
        },
        _normalizeRatio: function (x, y) {
            if (this.$view) {
                x = x || this.$view.clientWidth;
                y = y || this.$view.clientHeight;
                var div = this._gcdRatio(x, y), ratioX = (x / div).toString(), ratioY = (y / div).toString(), ratio = ratioX + ":" + ratioY;
                this._player.ratio = ratio;
            }
        }
    }, webix.ui.view, webix.EventSystem);

    var MediaPreview = (function (_super) {
        __extends(MediaPreview, _super);
        function MediaPreview() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        MediaPreview.prototype.config = function () {
            return {
                rows: [
                    {
                        localId: "albumArt",
                        hidden: true,
                        css: "webix_fmanager_preview",
                        template: "<img class=\"webix_fmanager_preview_icon\" src=\"" + this.app
                            .getService("backend")
                            .icon({ type: "audio" }, "big") + "\" />",
                    },
                    {
                        view: "plyr-player",
                        css: "webix_fmanager_player",
                        localId: "player",
                        config: {
                            controls: [
                                "play-large",
                                "play",
                                "progress",
                                "current-time",
                                "mute",
                                "volume",
                            ],
                        },
                    },
                ],
            };
        };
        MediaPreview.prototype.init = function () {
            var _this = this;
            var node = this.$$("albumArt").$view;
            webix.event(node, "dblclick", function () {
                var info = _this.getParam("info");
                _this.app.callEvent("app:action", ["open", [info]]);
            });
        };
        MediaPreview.prototype.urlChange = function () {
            var info = this.getParam("info");
            this.ShowPreview(info);
        };
        MediaPreview.prototype.ShowPreview = function (info) {
            if (!info)
                return;
            var url = this.app.getService("backend").directLink(info.id);
            this.SetMedia(url, info.type, info.$ext);
            var art = this.$$("albumArt");
            var player = this.$$("player");
            if (info.type === "audio") {
                player.config.height = 52;
                art.show();
            }
            else {
                player.config.height = 0;
                art.hide();
            }
        };
        MediaPreview.prototype.SetMedia = function (src, type, ext) {
            var player = this.$$("player");
            player.define({
                source: {
                    type: type,
                    sources: [
                        {
                            src: src,
                            type: type + "/" + ext,
                        },
                    ],
                },
            });
        };
        return MediaPreview;
    }(JetView));

    var TemplatePreview = (function (_super) {
        __extends(TemplatePreview, _super);
        function TemplatePreview() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        TemplatePreview.prototype.config = function () {
            return {
                view: "template",
                localId: "preview",
                css: "webix_fmanager_preview",
            };
        };
        TemplatePreview.prototype.init = function () {
            var _this = this;
            var node = this.getRoot().$view;
            webix.event(node, "dblclick", function () {
                var info = _this.getParam("info");
                if (info.type === "code" && _this.app.config.editor) {
                    _this.app.callEvent("app:action", ["edit", [info]]);
                }
                else if (info.type !== "folder" && info.type !== "empty") {
                    _this.app.callEvent("app:action", ["open", [info]]);
                }
            });
        };
        TemplatePreview.prototype.urlChange = function () {
            var info = this.getParam("info");
            this.ShowPreview(info);
        };
        TemplatePreview.prototype.ShowPreview = function (info) {
            var preview = this.$$("preview");
            if (info.type === "folder") {
                preview.setHTML("<img class=\"webix_fmanager_preview_icon\" src=\"" + this.app
                    .getService("backend")
                    .icon({ type: "folder" }, "big") + "\" />");
            }
            else if (info.type === "empty") {
                preview.setHTML("<img class=\"webix_fmanager_preview_icon\" src=\"" + this.app
                    .getService("backend")
                    .icon({ type: "none" }, "big") + "\" />");
            }
            else {
                var origin_1 = this.app.getService("backend").previewURL(info, 464, 407);
                preview.setHTML("<img style='width:100%; height:100%;' src='" + origin_1 + "' onerror='this.style.display=\"none\"'>");
            }
        };
        return TemplatePreview;
    }(JetView));

    var ProgressView = (function (_super) {
        __extends(ProgressView, _super);
        function ProgressView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        ProgressView.prototype.config = function () {
            var _this = this;
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
                            template: function (obj) {
                                return "<div class=\"webix_fmanager_progress_bar\">\n<div class=\"webix_fmanager_progress_counter\">" + obj.done + " of " + obj.total + " items</div>\n<div class=\"webix_fmanager_progress_name\">" + obj.file + "</div></div>";
                            },
                            localId: "counter",
                            borderless: true,
                            height: 59,
                        },
                        {
                            view: "button",
                            value: "Stop",
                            css: "webix_fmanager_progress_cancel",
                            click: function () {
                                _this.State.cancel = true;
                                _this.getRoot().disable();
                            },
                        },
                    ],
                },
            };
        };
        ProgressView.prototype.ready = function () {
            var _this = this;
            this.WaitClose = webix.promise.defer();
            this.Counter = this.$$("counter");
            this.Counter.setValues({
                done: 0,
                total: this.Config.files.length,
                file: this.Config.files[0],
            });
            webix.extend(this.Counter, webix.ProgressBar);
            this.State = createState({ i: 0, cancel: false });
            iterateAsync(this.Config.files, this.Config.code, this.State).finally(function () {
                return _this.Close();
            });
            this.on(this.State.$changes, "i", function (i) { return i && _this.Step(i); });
        };
        ProgressView.prototype.Close = function () {
            this.Counter.showProgress({ type: "bottom", position: 1, delay: 100 });
            this.show("_blank", { target: "popup" });
            this.WaitClose.resolve();
        };
        ProgressView.prototype.Step = function (i) {
            var done = this.Counter.getValues().done;
            this.Counter.setValues({ done: i, file: this.Config.files[i - 1] }, true);
            done = (done + 1) / this.Config.files.length;
            this.Counter.showProgress({
                type: "bottom",
                position: Math.min(1, done),
                delay: 100,
            });
        };
        return ProgressView;
    }(JetView));

    webix.protoUI({
        name: "r-layout",
        sizeTrigger: function (width, handler, value) {
            this._compactValue = value;
            this._compactWidth = width;
            this._compactHandler = handler;
            this._checkTrigger(this.$view.width, value);
        },
        _checkTrigger: function (x, value) {
            if (this._compactWidth) {
                if ((x <= this._compactWidth && !value) ||
                    (x > this._compactWidth && value)) {
                    this._compactWidth = null;
                    this._compactHandler(!value);
                    return false;
                }
            }
            return true;
        },
        $setSize: function (x, y) {
            if (this._checkTrigger(x, this._compactValue))
                return webix.ui.layout.prototype.$setSize.call(this, x, y);
        },
    }, webix.ui.layout);

    var TopBarView = (function (_super) {
        __extends(TopBarView, _super);
        function TopBarView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        TopBarView.prototype.config = function () {
            var _this = this;
            var compact = this.getParam("compact");
            var skin = webix.skin.$active;
            var _ = this.app.getService("locale")._;
            var bar = {
                view: "toolbar",
                height: skin.toolbarHeight + 12,
                margin: 20,
                paddingY: 9,
                paddingX: 12,
                cols: [
                    {
                        view: "icon",
                        icon: "webix_fmanager_icon fmi-file-tree",
                        click: function () { return _this.app.callEvent("app:action", ["toggle-folders"]); },
                        hidden: !compact,
                    },
                    { view: "label", label: _("Files"), autowidth: true, hidden: compact },
                    {
                        view: "search",
                        width: compact ? 0 : 300,
                        localId: "search",
                        placeholder: _("Search files and folders"),
                    },
                    { hidden: compact },
                    {
                        view: "toggle",
                        css: "webix_fmanager_preview_toggle",
                        type: "icon",
                        icon: "wxi-eye",
                        tooltip: _("Preview"),
                        width: skin.toolbarHeight < 37 ? 48 : 60,
                        localId: "previewMode",
                        click: function () { return _this.app.callEvent("app:action", ["toggle-preview"]); },
                        hidden: compact,
                    },
                    {
                        view: "segmented",
                        width: 124,
                        optionWidth: 40,
                        localId: "modes",
                        tooltip: function (conf) {
                            switch (conf.id) {
                                case "grid":
                                    return _("Table");
                                case "cards":
                                    return _("Cards");
                                case "double":
                                    return _("Total");
                                default:
                                    return capitalize(conf.id + "");
                            }
                        },
                        options: [
                            { value: gridMode, id: "grid" },
                            { value: cardMode, id: "cards" },
                            { value: doubleMode, id: "double" },
                        ],
                    },
                ],
            };
            return bar;
        };
        TopBarView.prototype.init = function () {
            var common = this.getParam("state");
            var modes = this.$$("modes");
            modes.attachEvent("onChange", function (v) {
                if (v) {
                    common.$batch({
                        mode: v,
                        search: "",
                    });
                }
            });
            this.on(common.$changes, "mode", function (v) {
                if (modes.getOption(v))
                    modes.setValue(v);
                else
                    modes.setValue();
            });
            var search = this.$$("search");
            search.attachEvent("onTimedKeyPress", function () {
                common.search = search.getValue().trim();
            });
            this.on(common.$changes, "search", function (s) {
                s = s.trim();
                search.setValue(s);
            });
            this.on(handler(), (webix.env.isMac ? "COMMAND" : "CTRL") + " + F", function (v, e) {
                search.focus();
                webix.html.preventEvent(e);
            });
        };
        return TopBarView;
    }(JetView));

    var TopView = (function (_super) {
        __extends(TopView, _super);
        function TopView() {
            return _super !== null && _super.apply(this, arguments) || this;
        }
        TopView.prototype.config = function () {
            var fCompact = this.getParam("forceCompact");
            if (typeof fCompact !== "undefined")
                this.setParam("compact", fCompact);
            this.Compact = this.getParam("compact");
            var tree = {
                view: "proxy",
                batch: "tree",
                width: 240,
                minWidth: 240,
                maxWidth: 400,
                hidden: true,
                borderless: true,
                body: FoldersView,
            };
            var panels = {
                type: "wide",
                localId: "folders",
                cols: [
                    { $subview: true, name: "center", branch: true },
                    {
                        view: "proxy",
                        borderless: true,
                        width: 470,
                        hidden: true,
                        localId: "r-side",
                        body: {
                            $subview: "",
                            branch: true,
                            name: "r-side",
                        },
                    },
                ],
            };
            if (!this.Compact)
                panels.cols.unshift(tree, { view: "resizer", batch: "tree" });
            return {
                view: typeof fCompact === "undefined" ? "r-layout" : "layout",
                type: "wide",
                rows: [TopBarView, panels, { $subview: true, popup: true, name: "popup" }],
            };
        };
        TopView.prototype.init = function () {
            var _this = this;
            var root = this.getRoot();
            if (root.sizeTrigger)
                root.sizeTrigger(this.app.config.compactWidth, function (mode) { return _this.SetCompactMode(mode); }, !!this.Compact);
            var state = this.getParam("state");
            this.State = state;
            if (this.Compact) {
                this.SideTree = this.ui(SideTreeView);
                this.PreviewPopup = this.ui(PreviewPopupView);
            }
            this.on(this.app, "app:action", function (name) {
                switch (name) {
                    case "toggle-preview":
                        _this.TogglePreview();
                        break;
                    case "toggle-folders":
                        _this.ToggleFolders();
                        break;
                }
            });
            this.on(state.$changes, "mode", function (v, o) { return _this.ShowMode(v, o); });
            this.on(state.$changes, "search", function (v) { return _this.ShowSearch(v); });
            this.app
                .getService("progress")
                .handle(this.getRoot(), this.ShowProgress.bind(this));
        };
        TopView.prototype.ShowMode = function (v, o) {
            var folders = this.$$("folders");
            var pos = {
                target: "center",
                params: {
                    state: this.getParam("state"),
                    compact: this.Compact,
                },
            };
            switch (v) {
                case "grid":
                case "cards":
                    folders.showBatch("tree");
                    this.show("panel/" + (v == "grid" ? "list" : v), pos);
                    break;
                case "double":
                    folders.showBatch("");
                    this.show("panel-double", pos);
                    break;
                case "search": {
                    this.PrevMode = this.PrevMode || o;
                    folders.showBatch("");
                    this.show("panel-search/cards", pos);
                    break;
                }
            }
        };
        TopView.prototype.ShowProgress = function (params) {
            var _this = this;
            return this.show("./progress", {
                target: "popup",
                params: params,
            }).then(function () { return _this.getSubView("popup"); });
        };
        TopView.prototype.TogglePreview = function () {
            if (!this.Compact) {
                var side = this.$$("r-side");
                if (!side.isVisible()) {
                    this.show("preview", {
                        target: "r-side",
                        params: { state: this.State },
                    });
                    side.show();
                }
                else {
                    this.show("_blank", { target: "r-side" });
                    side.hide();
                }
            }
            else {
                if (!this.PreviewPopup.IsVisible()) {
                    this.PreviewPopup.Show({ state: this.State });
                }
                else {
                    this.PreviewPopup.Hide();
                }
            }
        };
        TopView.prototype.ToggleFolders = function () {
            if (!this.SideTree.IsVisible()) {
                this.SideTree.Show();
            }
        };
        TopView.prototype.ShowSearch = function (value) {
            if (value) {
                this.State.mode = "search";
            }
            else {
                if (this.State.mode === "search")
                    this.State.mode = this.PrevMode || "grid";
                this.PrevMode = "";
            }
        };
        TopView.prototype.SetCompactMode = function (mode) {
            this.setParam("compact", mode);
            this.refresh();
        };
        return TopView;
    }(JetView));

    var views = { JetView: JetView };
    views["cards"] = CardsView;
    views["editor"] = EditorView;
    views["folders"] = FoldersView;
    views["list"] = GridView;
    views["menus/addnewmenu"] = AddNewMenuView;
    views["menus/contextmenu"] = ContextMenuView;
    views["menus/menubody"] = MenuBodyView;
    views["mobile/previewpopup"] = PreviewPopupView;
    views["mobile/sidetree"] = SideTreeView;
    views["panel-double"] = DoublePanelView;
    views["panel-search"] = PanelSearchView;
    views["panel"] = PanelView;
    views["preview"] = PreviewView;
    views["preview/info"] = InfoPreview;
    views["preview/media"] = MediaPreview;
    views["preview/template"] = TemplatePreview;
    views["progress"] = ProgressView;
    views["sections/dataview"] = DataViewBase;
    views["top"] = TopView;
    views["topbar"] = TopBarView;

    var en = {
        Save: "Save",
        "Save all": "Save all",
        Rename: "Rename",
        Open: "Open",
        Edit: "Edit",
        Delete: "Delete",
        Folder: "Folder",
        "Add New": "Add New",
        Size: "Size",
        Date: "Date",
        "back to parent folder": "back to parent folder",
        Download: "Download",
        Type: "Type",
        Information: "Information",
        Files: "Files",
        Table: "Table",
        Cards: "Cards",
        Total: "Total",
        "Are you sure ?": "Are you sure ?",
        Details: "Details",
        "Enter a new name": "Enter a new name",
        Add: "Add",
        "Select something": "Select something",
        "Download file": "Download file",
        Preview: "Preview",
        Refresh: "Refresh",
        "Are you sure you want to exit without saving?": "Are you sure you want to exit without saving?",
        "Save before closing?": "Save before closing?",
        Copy: "Copy",
        Cut: "Cut",
        Paste: "Paste",
        "Deleting...": "Deleting...",
        "Copying...": "Copying...",
        "Moving...": "Moving...",
        Folders: "Folders",
        "Search results": "Search results",
        "Search results in": "Search results in",
        "Search files and folders": "Search files and folders",
        "Add new file": "Add new file",
        "Add new folder": "Add new folder",
        "Upload file": "Upload file",
        folder: "folder",
        file: "file",
        archive: "archive",
        audio: "audio",
        image: "image",
        video: "video",
        code: "code",
        document: "document",
        of: "of",
        used: "used",
        "Open item location": "Open item location",
        "Are you sure you want to delete": "Are you sure you want to delete",
        "these items:": "these items:",
        "this item:": "this item:",
        "Delete files": "Delete files",
        and: "and",
        "more file(s)": "more file(s)",
        "Close the editor": "Close the editor",
        "Close this file": "Close this file",
        "Owner": "Owner",
        "Permissions": "Permissions",
        "Change Permissions": "Change Permissions",
        "Set": "Set",
        "Extract": "Extract",
        "Extract directory": "Extract directory",
        "Extracting...": "Extracting...",
        "Compress": "Compress",
        "Compressing...": "Compressing...",
    };

    var Backend = (function () {
        function Backend(app, url) {
            this.app = app;
            this._url = url;
            this._features = { preview: {}, meta: {} };
        }
        Backend.prototype.url = function (path) {
            return this._url + path;
        };
        Backend.prototype.search = function (id, search) {
            id = id || "/";
            return this._files(this.url("files"), { id: id, search: search });
        };
        Backend.prototype.files = function (id) {
            id = id || "/";
            return this._files(this.url("files"), { id: id });
        };
        Backend.prototype._files = function (url, params) {
            var data = webix.ajax(url, params).then(function (res) {
                return res.json();
            });
            return data;
            // stefan wieczorek
            //return this.getInfo().then(function () { return data; });
        };
        Backend.prototype.folders = function (id) {
            id = id || "/";
            var data = webix.ajax(this.url("folders"), { id: id });
            return data.then(function (a) {
                return a.json();
            });
        };
        Backend.prototype.icon = function (obj, size) {
            return this.url("icons/" + (size || "small") + "/" + obj.type + "/" + obj.$ext + ".svg");
        };
        Backend.prototype.upload = function () {
            return this.url("upload");
        };
        Backend.prototype.readText = function (id) {
            return webix.ajax(this.url("text"), { id: id }).then(function (data) { return data.text(); });
        };
        Backend.prototype.writeText = function (id, content) {
            return webix
                .ajax()
                .post(this.url("text"), {
                id: id,
                content: content,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.directLink = function (id, download) {
            return this.url("direct?id=" + encodeURIComponent(id) + (download ? "&download=true" : ""));
        };
        Backend.prototype.previewURL = function (obj, width, height) {
            if (!this._features.preview[obj.type])
                return this.icon(obj, "big");
            return this.url("preview?width=" + width + "&height=" + height + "&id=" + encodeURIComponent(obj.id));
        };
        Backend.prototype.remove = function (id) {
            return webix
                .ajax()
                .post(this.url("delete"), {
                id: id,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.makedir = function (id, name) {
            return webix
                .ajax()
                .post(this.url("makedir"), {
                id: id,
                name: name,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.makefile = function (id, name) {
            return webix
                .ajax()
                .post(this.url("makefile"), {
                id: id,
                name: name,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.copy = function (id, to) {
            return webix
                .ajax()
                .post(this.url("copy"), {
                id: id,
                to: to,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.move = function (id, to) {
            return webix
                .ajax()
                .post(this.url("move"), {
                id: id,
                to: to,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.rename = function (id, name) {
            return webix
                .ajax()
                .post(this.url("rename"), {
                id: id,
                name: name,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.permissions = function (id, permissions) {
            return webix
                .ajax()
                .post(this.url("permissions"), {
                    id: id,
                    permissions: permissions,
                })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.extract = function (id, destinationDirectoryName) {
            return webix
                .ajax()
                .post(this.url("extract"), {
                    id: id,
                    destinationDirectoryName: destinationDirectoryName,
                })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.compress = function (files, target) {
            return webix
                .ajax()
                .post(this.url("compress"), {
                    files: files,
                    target: target,
                })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.getRootName = function () {
            var _ = this.app.getService("locale")._;
            return _("Files");
        };
        Backend.prototype.getMeta = function (obj) {
            if (!this._features.meta[obj.type])
                return false;
            return webix
                .ajax(this.url("meta"), {
                id: obj.id,
            })
                .then(function (r) { return r.json(); });
        };
        Backend.prototype.getInfo = function (force) {
            var _this = this;
            if (this._info && !force)
                return this._info;
            return (this._info = webix.ajax(this.url("info")).then(function (resp) {
                resp = resp.json();
                _this._features = resp.features;
                return resp;
            }));
        };
        return Backend;
    }());

    var Cache = (function () {
        function Cache(limit) {
            this._store = new Map();
            this._limit = limit;
            this._i = 1;
        }
        Cache.prototype.each = function (code) {
            this._store.forEach(function (a) { return code(a.obj); });
        };
        Cache.prototype.set = function (key, obj) {
            if (this._store.size >= this._limit)
                this.prune();
            this._store.set(key, {
                obj: obj,
                key: key,
                t: this._i++,
            });
        };
        Cache.prototype.get = function (key) {
            var rec = this._store.get(key);
            if (!rec)
                return null;
            rec.t = this._i++;
            return rec.obj;
        };
        Cache.prototype.prune = function () {
            var temp = [];
            this._store.forEach(function (a) { return temp.push(a); });
            temp = temp.sort(function (a, b) { return (a.t > b.t ? -1 : 1); });
            for (var i = Math.floor(this._limit / 2); i < temp.length; i++) {
                this._store.delete(temp[i].key);
            }
        };
        Cache.prototype.delete = function (key) {
            if (this._store.has(key))
                this._store.delete(key);
        };
        Cache.prototype.clear = function () {
            this._store.clear();
        };
        return Cache;
    }());

    var LocalData = (function () {
        function LocalData(app, size) {
            this.app = app;
            this.fscache = new Cache(size);
            this.hierarchy = new webix.TreeCollection();
            this.folders_ready = null;
        }
        LocalData.prototype.defaultDir = function (id) {
            var dir = [];
            if (id !== "/")
                dir.push({
                    type: "folder",
                    value: "..",
                    $row: function (obj, common) {
                        return (common.backIcon() +
                            ("<span class='webix_fmanager_back'>" + common.backLabel() + "</span>"));
                    },
                });
            return dir;
        };
        LocalData.prototype.files = function (path, sync) {
            var fs = this.fscache.get(path);
            if (sync)
                return fs;
            if (fs)
                return Promise.resolve(fs);
            fs = new webix.DataCollection({
                scheme: {
                    $change: this.prepareData,
                    $serialize: this.serializeData,
                },
            });
            this.fscache.set(path, fs);
            return this.reload(fs, path);
        };
        LocalData.prototype.serializeData = function (a) {
            if (a.value == "..")
                return false;
            else {
                var o = {};
                for (var f in a) {
                    if (f.indexOf("$") !== 0)
                        o[f] = a[f];
                }
                return o;
            }
        };
        LocalData.prototype.prepareData = function (a) {
            if (typeof a.date === "number")
                a.date = new Date(a.date * 1000);
            if (a.type === "folder")
                a.$css = a.type;
            else
                a.$ext = ext(a.value);
        };
        LocalData.prototype.reload = function (fs, path) {
            var _this = this;
            return this.app
                .getService("backend")
                .files(path)
                .then(function (data) {
                fs.clearAll();
                fs.parse(_this.defaultDir(path).concat(data));
                return fs;
            });
        };
        LocalData.prototype.refresh = function (path) {
            var fs = this.fscache.get(path);
            if (fs)
                return this.reload(fs, path);
        };
        LocalData.prototype.addFile = function (id, item, dir) {
            var _this = this;
            var fs = this.fscache.get(id);
            if (fs) {
                if (!fs.exists(item.id)) {
                    fs.add(item, this.getFsPosition(fs, item));
                }
            }
            if (item.type === "folder") {
                if (id === "/")
                    id = "../files";
                this.hierarchy.add(item, null, id);
                if (dir)
                    this.app
                        .getService("backend")
                        .folders(item.id)
                        .then(function (data) {
                        return data.length && _this.hierarchy.parse({ parent: item.id, data: data });
                    });
            }
        };
        LocalData.prototype.getFsPosition = function (fs, item) {
            if (item.type !== "folder")
                return -1;
            var d = fs.data;
            return d.order.findIndex(function (a) { return d.getItem(a).type !== "folder"; });
        };
        LocalData.prototype.deleteFile = function (item) {
            var _a = this, fscache = _a.fscache, hierarchy = _a.hierarchy;
            fscache.each(function (fs) {
                if (fs && fs.exists(item))
                    fs.remove(item);
            });
            fscache.delete(item);
            if (hierarchy.exists(item))
                hierarchy.remove(item);
        };
        LocalData.prototype.updateFile = function (oldId, data, newId) {
            var hierarchy = this.hierarchy;
            this.fscache.each(function (fs) {
                if (fs && fs.exists(oldId)) {
                    fs.updateItem(oldId, data);
                    if (newId && oldId != newId)
                        fs.data.changeId(oldId, newId);
                }
            });
            if (hierarchy.exists(oldId)) {
                hierarchy.updateItem(oldId, data);
                if (newId && oldId != newId) {
                    hierarchy.data.changeId(oldId, newId);
                }
            }
        };
        LocalData.prototype.defaultTree = function () {
            return [{ value: "Files", id: "../files", open: true }];
        };
        LocalData.prototype.folders = function (force) {
            var _this = this;
            var hierarchy = this.hierarchy;
            if (force || !this.folders_ready) {
                this.folders_ready = this.app
                    .getService("backend")
                    .folders()
                    .then(function (data) {
                      hierarchy.clearAll();
                      hierarchy.parse(_this.defaultTree());
                      hierarchy.parse({ parent: "../files", data: data });
                      return hierarchy;
                });
            }
            return this.folders_ready;
        };
        return LocalData;
    }());

    var UploadHandler = (function () {
        function UploadHandler(app, state) {
            this.initUploader(app);
            this.initEvents(app, state);
        }
        UploadHandler.prototype.initEvents = function (app, state) {
            app.attachEvent("app:action", function (name, info) {
                if (name == "upload") {
                    info = info || (state.path || "/");
                    app.getService("upload").fileDialog(info);
                }
            });
        };
        UploadHandler.prototype.initUploader = function (app) {
            this.uploader = webix.ui({
                view: "uploader",
                apiOnly: true,
                upload: app.getService("backend").upload(),
                on: {
                    onAfterFileAdd: function (item) {
                        item.urlData = this.config.tempUrlData;
                    },
                    onFileUpload: function (file, res) {
                        app.getService("local").addFile(file.urlData.id, res);
                    },
                    onUploadComplete: function (file, res) {
                        if (file.uid) {
                            // Stefan Wieczorek
                            app.getService("local").refresh(file.uid);
                            app.getService("operations").reloadBranch(file.uid).then(function () {});
                        }
                        app.getService("progress").end();
                        app.callEvent("reload:fs:stats", []);
                    },
                },
            });
            this.uploader.$updateProgress = function (_, percent) {
                var progress = percent / 100;
                if (progress)
                    app.getService("progress").start(progress);
            };
        };
        UploadHandler.prototype.getUploader = function () {
            return this.uploader;
        };
        UploadHandler.prototype.fileDialog = function (id) {
            this.uploader.config.tempUrlData = { id: id };
            this.uploader.fileDialog();
        };
        return UploadHandler;
    }());

    var Progress = (function () {
        function Progress() {
            this.view = null;
            this.popup = null;
        }
        Progress.prototype.handle = function (view, popup) {
            this.view = view;
            webix.extend(view, webix.ProgressBar);
            this.popup = popup;
        };
        Progress.prototype.start = function (size) {
            var view = this.view;
            if (!view || view.$destructed)
                return;
            view.showProgress({
                type: "top",
                delay: 3000,
                hide: true,
                position: size
            });
        };
        Progress.prototype.end = function () {
            var view = this.view;
            if (!view || view.$destructed)
                return;
            view.hideProgress();
        };
        Progress.prototype.files = function (head, files, code) {
            var _this = this;
            if (!files.length)
                return;
            if (files.length == 1) {
                this.start();
                let counter = 0;
                let timer = setInterval(() => {
                    this.start(counter);
                }, 1000);
                return code(files[0], 0).finally(function () {
                    clearInterval(timer);
                    return _this.end();
                });
            }
            if (this.popup)
                return this.popup({
                    config: { head: head, files: files, code: code },
                }).then(function (popup) { return popup.WaitClose; });
        };
        Progress.prototype.extract = function (head, file, destinationDirectory, code) {
            var _this = this;
            let counter = 0;
            let timer = setInterval(() => {
                this.start(counter);
            }, 1000);
            return code(file, destinationDirectory).finally(function () {
                clearInterval(timer);
                return _this.end();
            });
        };
        Progress.prototype.compress = function (head, files, target, code) {
            var _this = this;
            if (!files.length)
                return;
            let counter = 0;
            let timer = setInterval(() => {
                this.start(counter);
            }, 1000);
            return code(files, target).finally(function () {
                clearInterval(timer);
                return _this.end();
            });
        };
        return Progress;
    }());

    var Operations = (function () {
        function Operations(app, state) {
            this.app = app;
            this.state = state;
            this.initEvents();
        }
        Operations.prototype.backend = function () {
            return this.app.getService("backend");
        };
        Operations.prototype.local = function () {
            return this.app.getService("local");
        };
        Operations.prototype.initEvents = function () {
            var _this = this;
            this.app.attachEvent("app:action", function (name, info) {
                switch (name) {
                    case "open":
                        _this.open(info);
                        break;
                    case "download":
                        _this.download(info);
                        break;
                    case "edit":
                        _this.edit(info);
                        break;
                    case "delete":
                        _this.remove(info);
                        break;
                    case "makefile":
                        _this.makeFile(info);
                        break;
                    case "makedir":
                        _this.makeFolder(info);
                        break;
                    case "change-permissions":
                        _this.changePermissions(info);
                        break;
                    case "compress":
                        _this.compress(info);
                        break;
                    case "extract":
                        _this.extract(info);
                        break;
                    case "rename":
                        _this.rename(info);
                        break;
                    case "copy":
                    case "cut":
                        _this.addToClipboard(name);
                        break;
                    case "paste":
                        _this.paste(info);
                        break;
                    case "locate":
                        _this.goUp(info);
                }
            });
        };
        Operations.prototype.write = function (id, content) {
            var _this = this;
            return this.backend()
                .writeText(id, content)
                .then(function (data) {
                return _this.local().updateFile(id, { size: data.size, date: data.date });
            });
        };
        Operations.prototype.read = function (id) {
            return this.backend().readText(id);
        };
        Operations.prototype.makeFile = function (name) {
            var _this = this;
            var id = this.state.path || "/";
            this.backend()
                .makefile(id, name)
                .then(function (res) {
                if (!res.invalid)
                    _this.local().addFile(id, res);
            });
        };
        Operations.prototype.makeFolder = function (name) {
            var _this = this;
            var id = this.state.path || "/";
            this.backend()
                .makedir(id, name)
                .then(function (res) {
                if (!res.invalid)
                    _this.local().addFile(id, res);
            });
        };
        Operations.prototype.edit = function (files) {
            var state = this.state;
            if (!files)
                files = state.selectedItem;
            files = files.filter(function (file) { return file.type === "code"; });
            if (files.length) {
                this.app.show("/editor", {
                    params: {
                        files: files,
                        state: state,
                    },
                });
            }
        };
        Operations.prototype.open = function (files) {
            if (!files)
                files = this.state.selectedItem;
            for (var i = 0; i < files.length; ++i) {
                if (files[i].type != "folder") {
                    window.open(this.backend().directLink(files[i].id), "_blank");
                }
            }
        };
        Operations.prototype.download = function (files) {
            if (!files)
                files = this.state.selectedItem;
            window.open(this.backend().directLink(files[0].id, true), "_self");
        };
        Operations.prototype.remove = function (sfiles) {
            var _this = this;
            var state = this.state;
            var files = this.extractIds(sfiles || state.selectedItem);
            if (!files.length)
                return webix.promise.reject();
            var _ = this.app.getService("locale")._;
            return webix
                .confirm({
                title: _("Delete files"),
                text: this.removeConfirmMessage(sfiles || state.selectedItem),
                css: "webix_fmanager_confirm",
            })
                .then(function () {
                return _this.app
                    .getService("progress")
                    .files(_("Deleting..."), files, function (f) {
                    return _this.backend()
                        .remove(f)
                        .then(function (res) {
                        if (!res.invalid) {
                            _this.local().deleteFile(f);
                            if (state.path === f) {
                                state.path = "/";
                            }
                            _this.app.callEvent("pathChanged", [f, "/"]);
                            _this.app.callEvent("reload:fs:stats", []);
                        }
                    });
                });
            })
                .then(function () {
                if (state.search) {
                    state.search = state.search + " ";
                }
            });
        };
        Operations.prototype.removeConfirmMessage = function (files) {
            var _ = this.app.getService("locale")._;
            var message = "<div class=\"question\">" + _("Are you sure you want to delete") + " " + (files.length > 1 ? _("these items:") : _("this item:")) + "</div>";
            var i = 0;
            var icon = "&#9679;&nbsp;";
            for (var limit = files.length < 6 ? files.length : 5; i < limit; ++i) {
                message += "<div class=\"item\">" + icon + files[i].value + "</div>";
            }
            if (i < files.length) {
                message += "<div>" + icon + _("and") + " " + (files.length - i) + " " + _("more file(s)") + "</div>";
            }
            return message;
        };
        Operations.prototype.rename = function (f) {
            var _this = this;
            var state = this.state;
            var file = f ? f[0] : state.selectedItem[0];
            if (!file)
                return;
            var _ = this.app.getService("locale")._;
            var oldId = file.id;
            prompt({
                text: _("Enter a new name"),
                button: _("Rename"),
                value: file.value,
                selectMask: file.type !== "folder" ? fileNameSelectMask : null,
            }).then(function (name) {
                name = safeName(name);
                if (name && name !== file.value)
                    _this.backend()
                        .rename(oldId, name)
                        .then(function (res) {
                        _this.local().updateFile(oldId, { value: res.id.split("/").pop() }, res.id);
                        if (file.type === "folder")
                            _this.reloadBranch(res.id).then(function () {
                                if (state.path === oldId) {
                                    state.path = res.id;
                                }
                                _this.app.callEvent("pathChanged", [oldId, res.id]);
                            });
                        if (state.search) {
                            state.search = state.search + " ";
                        }
                    });
            });
        };
        Operations.prototype.changePermissions = function (f) {
            var _this = this;
            var state = this.state;
            var file = f ? f[0] : state.selectedItem[0];
            if (!file)
                return;
            var _ = this.app.getService("locale")._;
            var oldId = file.id;
            prompt({
                text: _("Permissions"),
                button: _("Set"),
                value: file.permissions,
                selectMask: file.type !== "folder" ? fileNameSelectMask : null,
            }).then(function (permissions) {
                _this.backend()
                .permissions(oldId, permissions)
                .then(function (res) {
                  _this.local().updateFile(oldId, { permissions: res.permissions }, res.id);
                  if (file.type === "folder")
                    _this.reloadBranch(res.id).then(function () {
                    if (state.path === oldId) {
                      state.path = res.id;
                    }
                    _this.app.callEvent("pathChanged", [oldId, res.id]);
                  });
                  if (state.search) {
                    state.search = state.search + " ";
                  }
                });
            });
        };
        Operations.prototype.extract = function (f) {
            var _this = this;
            var state = this.state;
            var file = f ? f[0] : state.selectedItem[0];
            if (!file)
                return;
            var extractDirectory = file.value.replace(/\.[^/.]+$/, "").toLowerCase();
            if (file.value.slice(file.value.length - 6) == "tar.gz") {
                extractDirectory = file.value.substr(0, (file.value.length - 7));
            }
            var _ = this.app.getService("locale")._;
            var local = this.local();
            prompt({
                text: _("Extract directory"),
                button: _("Extract"),
                value: extractDirectory,
                selectMask: null,
            }).then(function (extractDirectory) {
                return _this.app
                    .getService("progress")
                    .extract(_("Extracting..."), file, extractDirectory, function (f) {
                        return _this.backend()
                            .extract(file.id, extractDirectory)
                            .then(function (res) {
                                if (res.path) {
                                  _this.reloadBranch(res.path).then(function () {});
                                  return local.refresh(state.path);
                                } else {
                                    if (res.error) {
                                        alert(res.error);
                                    }
                                }
                            });
                    });
             })
        };
        Operations.prototype.compress = function (files) {
            var _this = this;
            var state = this.state;
            if (!files.length)
                return webix.promise.reject();
            var local = this.local();
            var _ = this.app.getService("locale")._;
            var target = state.path;
            return _this.app
                .getService("progress")
                .compress(_("Compressing..."), files, target, function (f) {
                    return _this.backend()
                        .compress(f, target)
                        .then(function (res) {
                            if (res.path) {
                                _this.reloadBranch(res.path).then(function () {});
                                return local.refresh(state.path);
                            } else {
                                if (res.error) {
                                    alert(res.error);
                                }
                            }
                        });
                });
        };
        Operations.prototype.reloadBranch = function (id) {
            var hierarchy = this.local().hierarchy;
            return this.app
                .getService("backend")
                .folders(id)
                .then(function (data) {
                var toRemove = [];
                hierarchy.data.eachChild(id, function (obj) { return toRemove.push(obj.id); });
                hierarchy.parse({ parent: id, data: data });
                hierarchy.remove(toRemove);
            });
        };
        Operations.prototype.copy = function (files, targetFolder) {
            var _this = this;
            if (!files.length)
                return webix.promise.reject();
            var local = this.local();
            var _ = this.app.getService("locale")._;
            return this.app.getService("progress").files(_("Copying..."), files, function (f) {
                return _this.backend()
                    .copy(f, targetFolder)
                    .then(function (res) {
                    if (!res.invalid) {
                        local.addFile(targetFolder, res, true);
                    }
                });
            });
        };
        Operations.prototype.move = function (files, targetFolder) {
            var _this = this;
            if (!files.length || !targetFolder)
                return webix.promise.reject();
            var local = this.local();
            var tfs = local.files(targetFolder, true);
            if (tfs)
                files = files.filter(function (a) { return !tfs.exists(a); });
            files = files.filter(function (a) { return a != targetFolder; });
            if (!files.length)
                return webix.promise.reject();
            var _ = this.app.getService("locale")._;
            return this.app.getService("progress").files(_("Moving..."), files, function (f) {
                return _this.backend()
                    .move(f, targetFolder)
                    .then(function (res) {
                    if (!res.invalid) {
                        local.deleteFile(f);
                        local.addFile(targetFolder, res, true);
                        if (_this.state.path === f) {
                            _this.state.path = res.id;
                        }
                        _this.app.callEvent("pathChanged", [f, res.id]);
                    }
                });
            });
        };
        Operations.prototype.extractIds = function (files) {
            if (!files.length || typeof files[0] == "string")
                return files;
            var ids = [];
            for (var i = 0; i < files.length; ++i) {
                if (files[i].value !== "..")
                    ids.push(files[i].id);
            }
            return ids;
        };
        Operations.prototype.addToClipboard = function (mode) {
            var files = this.state.selectedItem;
            if (files.length)
                this.state.clipboard = {
                    files: files,
                    mode: mode,
                };
        };
        Operations.prototype.clearClipboard = function () {
            this.state.clipboard = null;
        };
        Operations.prototype.paste = function (files) {
            var state = this.state;
            if (!state.clipboard)
                return;
            var target = state.path;
            if (files && files[0].type == "folder")
                target = files[0].id;
            if (state.clipboard.mode === "copy") {
                this.copy(this.extractIds(state.clipboard.files), target);
            }
            else if (state.clipboard.mode === "cut") {
                this.move(this.extractIds(state.clipboard.files), target);
            }
            this.clearClipboard();
        };
        Operations.prototype.goUp = function (files) {
            var state = this.state;
            if (state.search) {
                var file = files ? files[0] : state.selectedItem[0];
                if (!file)
                    return;
                var up = file.id.split("/");
                var path = "/" + up.slice(1, up.length - 1).join("/");
                state.$batch({
                    search: "",
                    searchStats: null,
                    path: path,
                });
            }
        };
        return Operations;
    }());

    var App = (function (_super) {
        __extends(App, _super);
        function App(config) {
            var _this = this;
            var state = createState({
                mode: config.mode || "grid",
                selectedItem: [],
                search: "",
                searchStats: null,
                path: config.path || "/",
                source: config.source || "files",
                clipboard: null,
            });
            var defaults = {
                router: EmptyRouter,
                version: "9.1.6",
                debug: true,
                start: "/top",
                params: { state: state, forceCompact: config.compact },
                editor: true,
                player: true,
                compactWidth: 640,
            };
            _this = _super.call(this, __assign(__assign({}, defaults), config)) || this;
            _this.setService("backend", new (_this.dynamic(Backend))(_this, _this.config.url));
            _this.setService("local", new (_this.dynamic(LocalData))(_this, 10));
            _this.setService("progress", new (_this.dynamic(Progress))(_this));
            _this.setService("upload", new (_this.dynamic(UploadHandler))(_this, state));
            _this.setService("operations", new (_this.dynamic(Operations))(_this, state));
            _this.use(plugins.Locale, _this.config.locale || {
                lang: "en",
                webix: {
                    en: "en-US",
                    de: "de-DE",
                    fr: "fr-FR",
                    zh: "zh-CN",
                },
            });
            return _this;
        }
        App.prototype.dynamic = function (obj) {
            return this.config.override ? this.config.override.get(obj) || obj : obj;
        };
        App.prototype.require = function (type, name) {
            if (type === "jet-views")
                return views[name];
            else if (type === "jet-locales")
                return locales[name];
            return null;
        };
        App.prototype.getState = function () {
            return this.config.params.state;
        };
        return App;
    }(JetApp));
    webix.protoUI({
        name: "filemanager",
        app: App,
        getState: function () {
            return this.$app.getState();
        },
        getService: function (name) {
            return this.$app.getService(name);
        },
        $init: function () {
            var state = this.$app.getState();
            for (var key in state) {
                link(state, this.config, key);
            }
        },
    }, webix.ui.jetapp);
    var services = { Backend: Backend, LocalData: LocalData, Upload: UploadHandler, Progress: Progress, Operations: Operations };
    var locales = { en: en};

    exports.App = App;
    exports.locales = locales;
    exports.services = services;
    exports.views = views;

    Object.defineProperty(exports, '__esModule', { value: true });

})));
