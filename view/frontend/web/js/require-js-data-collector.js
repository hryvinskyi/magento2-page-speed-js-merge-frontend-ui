/**
 * Copyright (c) 2022-2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

define(['jquery'], function ($) {
    return {
        init: function (key, url, tags, ignore) {
            this.baseUrl = require.s.contexts._.config.baseUrl;

            this.key = key;
            this.url = url;
            this.tags = tags;
            this.ignore = ignore;

            this.pushed = [];
            let self = this,
                jsBuild = Object.keys(require.s.contexts._.config.config.jsbuild || []),
                textBuild = Object.keys(require.s.contexts._.config.config.text || []);

            $.each($.merge(jsBuild, textBuild), function (key, module) {
                self.pushed.push(self.baseUrl + module);
            });
        },

        run: function () {
            let self = this;
            setInterval(function () {
                if (self.scripts().length > 0) {
                    self.doRequest();
                    self.pushed = $.merge(self.pushed, self.scripts());
                }
            }, 5000);
        },

        doRequest: function () {
            $.ajax(this.url, {
                method: 'post',
                data: {
                    key: this.key,
                    tags: this.tags,
                    base: this.baseUrl,
                    list: this.scripts()
                }
            });
        },

        scripts: function () {
            var self = this,
                jsList = Object.keys(require.s.contexts._.urlFetched),
                textList = [];

            $.each(Object.keys(require.s.contexts._.defined), function (i, module) {
                if (module.indexOf('text!') !== 0) {
                    return;
                }
                if (!module.match(/\.html$/)) {
                    return;
                }
                var name = module.replace(/^text!/, '');
                textList.push(require.toUrl(name));
            });
            let onPage = $.merge(jsList, textList);

            onPage = $.grep(
                onPage,
                function (v) {
                    if (v.indexOf(self.baseUrl.replace(/(https*:\/\/[^\/]+\/).*/, "$1")) !== 0) {
                        return false;
                    }
                    return $.grep(self.ignore, function (ignoreString) {
                        return v.indexOf(window.atob(ignoreString)) !== -1;
                    }).length === 0;
                }
            );

            return $(onPage).not(this.pushed).get();
        }
    };
});
