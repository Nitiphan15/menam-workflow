(function (window, document) {
    'use strict';

    function unique(items) {
        return items.filter(function (item, index) {
            return item && items.indexOf(item) === index;
        });
    }

    function resolveContainers(containers) {
        var resolved = [];
        (containers || []).forEach(function (container) {
            if (typeof container === 'string') {
                resolved = resolved.concat(Array.from(document.querySelectorAll(container)));
            } else if (container && typeof container === 'object') {
                resolved.push(container);
            }
        });
        return resolved;
    }

    function scrollableParents(element) {
        var parents = [];
        var current = element && element.parentElement;
        while (current && current !== document.body) {
            var style = window.getComputedStyle(current);
            var overflow = style.overflow + style.overflowX + style.overflowY;
            if (/(auto|scroll|overlay)/.test(overflow)) parents.push(current);
            current = current.parentElement;
        }
        return parents;
    }

    function capture(options) {
        var active = document.activeElement;
        var containers = unique([
            document.scrollingElement,
            document.querySelector('.main-col'),
            document.querySelector('.main-content')
        ].concat(scrollableParents(active), resolveContainers(options && options.containers)));

        return {
            windowX: window.scrollX,
            windowY: window.scrollY,
            containers: containers.map(function (element) {
                return { element: element, left: element.scrollLeft, top: element.scrollTop };
            })
        };
    }

    function restore(snapshot) {
        if (!snapshot) return;
        var apply = function () {
            window.scrollTo(snapshot.windowX, snapshot.windowY);
            snapshot.containers.forEach(function (position) {
                if (!position.element || !position.element.isConnected) return;
                position.element.scrollLeft = position.left;
                position.element.scrollTop = position.top;
            });
        };

        apply();
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(apply);
        });
    }

    function fire(options, scrollOptions) {
        if (!window.Swal || typeof window.Swal.fire !== 'function') {
            return Promise.reject(new Error('SweetAlert2 is not available'));
        }

        var config = Object.assign({}, options || {});
        var snapshot = capture(scrollOptions || {});
        var originalDidOpen = config.didOpen;
        var originalDidClose = config.didClose;

        config.returnFocus = false;
        if (typeof config.scrollbarPadding === 'undefined') config.scrollbarPadding = false;
        config.didOpen = function (popup) {
            restore(snapshot);
            if (typeof originalDidOpen === 'function') originalDidOpen(popup);
        };
        config.didClose = function () {
            restore(snapshot);
            if (typeof originalDidClose === 'function') originalDidClose();
        };

        return window.Swal.fire(config).then(function (result) {
            restore(snapshot);
            return result;
        });
    }

    function mixin(defaultOptions, defaultScrollOptions) {
        return {
            fire: function (options, scrollOptions) {
                return fire(
                    Object.assign({}, defaultOptions || {}, options || {}),
                    Object.assign({}, defaultScrollOptions || {}, scrollOptions || {})
                );
            }
        };
    }

    window.ScrollSafeSwal = { capture: capture, restore: restore, fire: fire, mixin: mixin };
})(window, document);
