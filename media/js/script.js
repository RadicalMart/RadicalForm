function ready(fn) {
    if (document.readyState !== 'loading'){
        fn();
    } else {
        document.addEventListener('DOMContentLoaded', fn);
    }
}

RadicalFormClass = function () {

    var selfClass = this;
    var uploadGroups = new WeakMap();
    var fileOperations = new WeakMap();
    var submittingForms = new WeakSet();
    var uploadControls = "input[type='file'].rf-upload-button, .rf-upload-button-text";
    this.formToken = '';
    this.formTokenPromise = null;

    /**
     * get uniq id for upload a file.
     * @type {number}
     */
    this.refreshUniq = function() {
        selfClass.uniq = (new Date).getTime() + Math.floor(Math.random() * 100);
    };
    this.refreshUniq();

    function releaseUploadGroup(form) {
        if (!fileOperations.has(form) && !form.querySelector('input[name="needToSendFiles"]')) {
            uploadGroups.delete(form);
        }
    }

    function disableControls(form, selector) {
        return Array.from(form.querySelectorAll(selector)).filter(function(control) {
            if (!('disabled' in control) || control.disabled || selfClass.closest(control, '.rf-form') !== form) {
                return false;
            }

            control.disabled = true;
            return true;
        });
    }

    function restoreControls(controls) {
        controls.forEach(function(control) {
            control.disabled = false;
        });
    }

    function beginFileOperation(form, kind) {
        var operation = fileOperations.get(form);
        if (!operation) {
            var selector = '.rf-button-send, ' + (kind === 'upload' ? '.rf-button-delete' : uploadControls);
            operation = {kind: kind, pending: 0, controls: disableControls(form, selector)};
            fileOperations.set(form, operation);
        }
        operation.pending++;

        return function() {
            operation.pending--;
            if (operation.pending === 0) {
                fileOperations.delete(form);
                restoreControls(operation.controls);
                releaseUploadGroup(form);
            }
        };
    }

    /**
     *
     * @type {array}
     */
    this.danger_classes = RadicalForm.DangerClass.trim().split(/\s+/);
    this.error_file_classes = RadicalForm.ErrorFile.trim().split(/\s+/);

    this.runRfCall = function(number, rfMessage, here) {
        var rfCallName = 'rfCall_' + number;

        if (typeof window[rfCallName] === 'function') {
            try {
                window[rfCallName](rfMessage, here);
            } catch (e) {
                console.error('Radical Form JS Code: ', e);
            }
        } else {
            console.error('RadicalForm: ' + rfCallName + ' is not set in plugin settings but requested by data-rf-call.');
        }
    };

    this.getServerErrorFields = function(response) {
        if (!response || !response.data) {
            return [];
        }

        if (Array.isArray(response.data.fields)) {
            return response.data.fields;
        }

        if (Array.isArray(response.data) && response.data[0] && Array.isArray(response.data[0].fields)) {
            return response.data[0].fields;
        }

        return [];
    };

    this.highlightFields = function(form, fields) {
        if (!Array.isArray(fields)) {
            return;
        }

        fields.forEach(function(fieldName) {
            [].forEach.call(form.querySelectorAll('[name]'), function(el) {
                if (el.name === fieldName) {
                    selfClass.danger_classes.forEach(function(item) {
                        el.classList.add(item);
                    });
                }
            });
        });
    };

    this.getReservedFieldNames = function(form) {
        var reservedFieldNames = Array.isArray(RadicalForm.ReservedFieldNames)
                ? RadicalForm.ReservedFieldNames
                : [],
            found = [];

        [].forEach.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function(el) {
            var fieldName = el.name.split('[')[0].toLowerCase();

            if (reservedFieldNames.indexOf(fieldName) !== -1 && found.indexOf(fieldName) === -1) {
                found.push(fieldName);
            }
        });

        return found;
    };

    this.showReservedFieldError = function(form, fieldNames, here) {
        var message = RadicalForm.ReservedFieldMessage.replace(
            '%s',
            fieldNames.map(function(fieldName) {
                return '"' + fieldName + '"';
            }).join(', ')
        );

        [].forEach.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function(el) {
            var fieldName = el.name.split('[')[0].toLowerCase();

            if (fieldNames.indexOf(fieldName) !== -1) {
                selfClass.danger_classes.forEach(function(item) {
                    el.classList.add(item);
                });
            }
        });

        console.error(message);

        try {
            rfCall_9(message, here);
        } catch (e) {
            console.error('Radical Form JS Code: ', e);
        }
    };

    this.postJsChallengeRequest = function(action, values) {
        var tokenPromise = selfClass.formTokenPromise || Promise.resolve(selfClass.formToken);

        return tokenPromise.then(function(token) {
            if (!token) {
                throw new Error(RadicalForm.JsChallengeMessage);
            }

            return new Promise(function(resolve, reject) {
                var request = new XMLHttpRequest(),
                    data = new FormData(),
                    requestUrl = RadicalForm.Base + '/index.php?option=com_ajax&plugin=radicalform&group=system&format=json';

                data.append('rfJsAction', action);
                data.append(token, '1');

                Object.keys(values || {}).forEach(function(key) {
                    data.append(key, values[key]);
                });

                request.open('POST', requestUrl, true);
                request.onloadend = function() {
                    var response = false;

                    try {
                        response = JSON.parse(this.response);
                    } catch (e) {
                        reject(new Error(RadicalForm.JsChallengeMessage));
                        return;
                    }

                    if (this.status >= 200 && this.status < 400 && response && response.success && response.data && response.data[0]) {
                        resolve(response.data[0]);
                        return;
                    }

                    reject(new Error((response && response.message) || RadicalForm.JsChallengeMessage));
                };
                request.send(data);
            });
        });
    };

    this.solveJsChallenge = function(challenge) {
        var value = Number(challenge.seed) & 0x7fffffff;

        if (!Array.isArray(challenge.operations)) {
            throw new Error(RadicalForm.JsChallengeMessage);
        }

        challenge.operations.forEach(function(operation) {
            var operand = Number(operation[1]) & 0x7fffffff;

            switch (operation[0]) {
                case 'add':
                    value = (value + operand) & 0x7fffffff;
                    break;
                case 'xor':
                    value = (value ^ operand) & 0x7fffffff;
                    break;
                case 'mul':
                    value = Math.imul(value, operand) & 0x7fffffff;
                    break;
                default:
                    throw new Error(RadicalForm.JsChallengeMessage);
            }
        });

        return String(value);
    };

    this.getJsPermit = function() {
        if (Number(RadicalForm.JsChallengeEnabled) !== 1) {
            return Promise.resolve('');
        }

        return selfClass.postJsChallengeRequest('challenge').then(function(challenge) {
            var proof = selfClass.solveJsChallenge(challenge),
                delay = Math.max(0, Number(challenge.minimumDelayMs) || 0);

            return new Promise(function(resolve) {
                window.setTimeout(resolve, delay);
            }).then(function() {
                return selfClass.postJsChallengeRequest('solve', {
                    rfJsChallenge: challenge.challenge,
                    rfJsProof: proof
                });
            });
        }).then(function(result) {
            if (!result.permit) {
                throw new Error(RadicalForm.JsChallengeMessage);
            }

            return result.permit;
        });
    };

    if (RadicalForm.KeepAlive != 0) {
        window.setInterval(function() {

            var request = new XMLHttpRequest();

            request.open('POST', RadicalForm.Base + '/index.php?option=com_ajax&format=json', true);

            request.onload = function() {

            };

            request.send();

        }, RadicalForm.TokenExpire);
    }

    /**
     * Init for DOM element
     * @param container
     */
    this.init = function(container) {

        if(typeof container === 'string') {
            container = document.querySelector(container);
        } else {
            if(container === null || container === undefined) {
                container = document.querySelector('body')
            }
        }

        //here we initialize all forms without .rf-form class and those don't have .rf-form inside - we added this class so they have it
        //only forms with this class is used in our work
        var allForms= Array.from(container.querySelectorAll('form:not(.rf-form)')),
            filteredForms;
        filteredForms = allForms.filter(function(el) {
            if(el.querySelector(".rf-form"))
            {
                // we don't add rf-form class to forms with our form inside
                return false;
            }
            return (el.querySelector(".rf-button-send"));
        });

        filteredForms.forEach(function (el){
            el.classList.add('rf-form');
        });

        var request1 = new XMLHttpRequest();
        var AjaxFormDataforToken = new FormData();
        AjaxFormDataforToken.append('gettoken', '1');
        request1.open('POST', RadicalForm.Base + '/index.php?option=com_ajax&plugin=radicalform&format=json&group=system', true);

        selfClass.formTokenPromise = new Promise(function(resolve) {
            request1.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    try {
                        var data = JSON.parse(this.response);
                        selfClass.formToken = data.data[0];
                        [].forEach.call(container.querySelectorAll('.rf-form .rf-button-send'), function (el) {
                            el.insertAdjacentHTML('afterend', '<input type="hidden" name="'+data.data[0]+'" value="1" />');
                        });
                        resolve(selfClass.formToken);
                        return;
                    } catch (e) {
                        console.error('RadicalForm token response: ', e);
                    }
                }

                resolve('');
            };
            request1.onloadend = function() {
                resolve('');
            };
        });
        request1.send(AjaxFormDataforToken);

        this.on(container, ".rf-form ." + selfClass.danger_classes.join('.'), 'keypress', function (target, e) {
            selfClass.danger_classes.forEach(function (item) {
                target.target.classList.remove(item);
            });
        });

        this.on(container, ".rf-form ." + selfClass.danger_classes.join('.'), 'change', function (target, e) {
            selfClass.danger_classes.forEach(function (item) {
                target.target.classList.remove(item);
            });
        });

        this.on(container, ".rf-form .rf-button-delete", 'click', function (target) {
            var row = selfClass.closest(target.target, "div[data-name]");
            var form = row && selfClass.closest(row, ".rf-form");
            var name = row && row.querySelector("span");
            var operation = form && fileOperations.get(form);
            if (!form || !name || this.disabled || submittingForms.has(form) || (operation && operation.kind === 'upload')) {
                return;
            }

            var uploadGroup = uploadGroups.get(form);
            var deleteData = new FormData();
            deleteData.append('deletefile', name.textContent);
            deleteData.append('uniq', uploadGroup ? uploadGroup.uniq : selfClass.uniq);
            deleteData.append('catalog', row.dataset.name);
            deleteData.append(selfClass.formToken, '1');

            var request = new XMLHttpRequest();
            request.open('POST', RadicalForm.Base + '/index.php?option=com_ajax&plugin=radicalform&format=json&group=system&file=delete', true);
            request.responseType = 'json';

            request.onload = function() {
                var response = this.response;
                if (this.status < 200 || this.status >= 300 || !response || !response.success
                    || !response.data || response.data[0] !== 'ok' || !row.parentNode) {
                    return;
                }

                row.remove();
                if (!form.querySelector('div[data-name] .rf-button-delete')) {
                    var needsFiles = form.querySelector('input[type="hidden"][name="needToSendFiles"]');
                    if (needsFiles) {
                        needsFiles.remove();
                    }
                }
            };

            var finishDeletion = beginFileOperation(form, 'delete');
            request.onloadend = finishDeletion;
            try {
                request.send(deleteData);
            } catch (error) {
                finishDeletion();
                throw error;
            }
        });

        this.on(container, '.rf-form', 'submit', function(event) {
            if (fileOperations.has(this) || submittingForms.has(this)) {
                event.preventDefault();
            }
        });

        var isFramed = false;
        try {
            isFramed = window != window.top || document != top.document || self.location != top.location;
        } catch (e) {
            isFramed = true;
        }
        if (!isFramed) {
            /* page loaded not in the frame - (Yootheme pagebuilder not loaded) */
            var invalidSendButtons = Array.from(container.querySelectorAll(".rf-button-send")).filter(function (el) {
                return !el.parentElement || !selfClass.closest(el.parentElement, ".rf-form");
            });

            if (invalidSendButtons.length) {
                var maxElementsInMessage = 5,
                    errorLines = [
                        "RadicalForm configuration error!",
                        "",
                        "Found " + invalidSendButtons.length + " element(s) with .rf-button-send outside .rf-form:",
                        ""
                    ];

                invalidSendButtons.slice(0, maxElementsInMessage).forEach(function (el, index) {
                    var elementText = (el.textContent || "").replace(/\s+/g, " ").trim(),
                        elementDescription = "<" + el.tagName.toLowerCase();

                    if (el.id) {
                        elementDescription += "#" + el.id;
                    }
                    if (el.classList.length) {
                        elementDescription += "." + Array.from(el.classList).join(".");
                    }
                    elementDescription += ">";

                    if (elementText.length > 80) {
                        elementText = elementText.substring(0, 77) + "...";
                    }

                    errorLines.push((index + 1) + ". " + elementDescription + (elementText ? ' — "' + elementText + '"' : ""));
                });

                if (invalidSendButtons.length > maxElementsInMessage) {
                    errorLines.push("... and " + (invalidSendButtons.length - maxElementsInMessage) + " more element(s).");
                }

                errorLines.push(
                    "",
                    "Move each element inside .rf-form, or remove .rf-button-send if it is not a form submit button.",
                    "Full element details are available in the browser console."
                );

                console.error(errorLines.join("\n"));
                var useConsoleGroup = typeof console.groupCollapsed === "function";
                if (useConsoleGroup) {
                    console.groupCollapsed("RadicalForm: invalid .rf-button-send elements");
                }
                invalidSendButtons.forEach(function (el, index) {
                    console.error("Element " + (index + 1) + ":", el);
                    console.log(el.outerHTML);
                });
                if (useConsoleGroup && typeof console.groupEnd === "function") {
                    console.groupEnd();
                }

                alert(errorLines.join("\r\n"));
            } else if (container.querySelectorAll(".rf-filenames-list").length !== container.querySelectorAll(".rf-form .rf-filenames-list").length) {
                alert('ERROR!\r\nThere is \r\n.rf-filenames-list\r\n outside of form!\r\n Please move .rf-filenames-list inside the form. ');
            }
        }


        [].forEach.call(container.querySelectorAll('.rf-form .rf-button-send'), function (el) {
            el.addEventListener('click', selfClass.formSend);
        });

        [].forEach.call(container.querySelectorAll("input[type='file'].rf-upload-button"), function (el) {
            el.addEventListener('change', selfClass.fileSend);
        });
    };

    /**
     * Send form
     * @param e
     */
    this.formSend = function(e) {
        var needReturn = false,
            field,
            form = selfClass.closest(this, '.rf-form'),
            reservedFieldNames;
        e.preventDefault();
        if (form === null ) {
            alert("There is no parent with css class .rf-form for your send button!\r\nSee possible explanation in console log.");
            console.log("If you use uikit 3 - it moves the modal window to the end of the document the moment it is opened.\n" +
                "\n" +
                "Thus, the window at the moment of its opening may not be where it was in the original layout.\n" +
                "\n" +
                "Check this with the browser's developer tools at the moment the modal window is open.");
            return;
        }

        if (this.disabled || fileOperations.has(form) || submittingForms.has(form)) {
            return;
        }

        reservedFieldNames = selfClass.getReservedFieldNames(form);

        if (reservedFieldNames.length) {
            selfClass.showReservedFieldError(form, reservedFieldNames, this);
            return;
        }

        var numberOfInputsWithNames=form.querySelectorAll('input[name], select[name], textarea[name]').length - form.querySelectorAll('input[type="file"]').length;
        if (numberOfInputsWithNames < 2) {
            alert("There is no input tags in your form with 'name' attribute!\r\n Please add 'name' attribute to your input tags!");
            needReturn = true;
        }
        if(form.querySelectorAll("input[name]").length !== form.querySelectorAll('input').length)
        {
            console.log('RadicalForm: there are inputs in your form without name! Please check ');
        }


        RadicalForm.FormFields = [];
        [].forEach.call(form.querySelectorAll("[name]"), function (el) {
           // remove danger classes so they can animated later
            selfClass.danger_classes.forEach(function (item) {
                el.classList.remove(item);
            });
            // let's see the fields of form to check for validity and required
            if ((el.classList.contains('required') && el.value.trim() === "") ||
                (el.classList.contains('required') && (!el.checked) && (el.type === "checkbox")) ||
                (!el.checkValidity())) {
                RadicalForm.FormFields.push(el);
                needReturn = true;
            }
        });


        setTimeout(function () {
            for (var i = 0; i < RadicalForm.FormFields.length; i++) {
                selfClass.danger_classes.forEach(function (item) {
                    RadicalForm.FormFields[i].classList.add(item);
                })
            }
        }, 70);

        if (fileOperations.has(form) || submittingForms.has(form)) {
            return;
        }
        submittingForms.add(form);

        if (this.dataset.rfCall !== undefined) {
            var rfCall = String(this.dataset.rfCall);
            if (rfCall[0] === "0") {
                if (typeof (rfCall_0) === "function") {
                    try {
                        var returnOfpreCall = rfCall_0(this, needReturn);
                        if ((returnOfpreCall !== undefined) && (returnOfpreCall === false)) {
                            needReturn = true;
                        }
                    } catch (e) {
                        console.error('Radical Form JS Code: ', e);
                    }
                }
                else
                {
                    console.error("Function rfCall_0 doesn't set at RadicalForm plugin settings");
                }
            }
        }

        if (!needReturn) {

            var previousButtonText = this.innerHTML,
                buttonPressed = this,
                uploadGroup = uploadGroups.get(form),
                disabledControls = disableControls(form, '.rf-button-send, .rf-button-delete, ' + uploadControls);
            this.innerHTML = RadicalForm.WaitMessage;

            var finishSubmission = function() {
                buttonPressed.innerHTML = previousButtonText;
                restoreControls(disabledControls);
                submittingForms.delete(form);
            };

            var AjaxFormData = new FormData(); //form data without the file inputs
            AjaxFormData.append('rfUserAgent', window.navigator.userAgent);
            if(form.getAttribute('id') !== null) {
                AjaxFormData.append('rfFormID', form.getAttribute('id'));
            }
            AjaxFormData.append('uniq', uploadGroup ? uploadGroup.uniq : selfClass.uniq);
            AjaxFormData.append('url', window.location.href);
            AjaxFormData.append('rf-time', selfClass.showTime());
            AjaxFormData.append('rf-duration', (performance.now()/1000).toFixed(2));
            AjaxFormData.append('reffer', document.referrer);
            AjaxFormData.append('resolution', screen.width + 'x' + screen.height);
            AjaxFormData.append('pagetitle', document.title.replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;"));


            var elements = form.querySelectorAll('input[name], select[name], textarea[name]');

            var option, optValue;
            RadicalForm.Contacts = {};

            for (var i = 0; i < elements.length; i++) {
                field = elements[i];
                switch (field.type){
                    case "select-one":
                    case "select-multiple":

                        if (field.name.length) {
                            for (var j = 0, optLen = field.options.length; j < optLen; j++) {
                                option = field.options[j];
                                if (option.selected) {
                                    optValue = "";
                                    if (option.hasAttribute) {
                                        optValue = (option.hasAttribute("value") ? option.value : option.text);
                                    } else {
                                        optValue = ( option.attributes["value"].specified ? option.value: option.text);
                                    }
                                    AjaxFormData.append(field.name, optValue);
                                }
                            }

                        }
                        break;
                    case undefined:
                    case "file":
                    case "submit":
                    case "reset":
                    case "button":
                        break;
                    case "radio":
                    case "checkbox":
                        if(!field.checked) {
                            break;
                        }
                    default:
                        if (field.name.length) {
                            AjaxFormData.append(field.name, field.value);
                            if (field.name === "phone") {
                                RadicalForm.Contacts.phone = field.value;
                            }
                            if (field.name === "name") {
                                RadicalForm.Contacts.name = field.value;
                            }
                            if (field.name === "email") {
                                RadicalForm.Contacts.email = field.value;
                            }
                        }

                }
            }


            if (RadicalForm.Jivosite === "1") {

                try {
                    if (Object.keys(RadicalForm.Contacts).length !== 0) {
                        jivo_api.setContactInfo(RadicalForm.Contacts);
                    }
                } catch (e) {
                    console.error('Radical Form JS Code: ', e);
                }

            }

            if (RadicalForm.Verbox === "1") {

                try {
                    if (Object.keys(RadicalForm.Contacts).length !== 0) {
                        Verbox("setClientInfo", RadicalForm.Contacts);
                    }
                } catch (e) {
                    console.error('Radical Form JS Code: ', e);
                }
            }

            var handleResponse = function() {
                var request = this, rfCall, message;
                finishSubmission();
                if (request.status === 200) {

                    var response = false;
                    try {
                        response = JSON.parse(request.response);
                        if (!response || typeof response.success !== 'boolean'
                            || (response.success && (!Array.isArray(response.data) || response.data[0] == null))) {
                            throw new Error('Unexpected Joomla response.');
                        }
                    } catch (e) {
                        response = false;
                        message = 'Invalid server response. Expected JSON.'
                            + '\nResponse code: ' + request.status
                            + (request.statusText ? ' ' + request.statusText : '')
                            + '\n' + e.message;
                        console.error(
                            message
                            + (request.responseText ? '\n' + request.responseText : '')
                        );
                        try {
                            rfCall_9(message, buttonPressed);
                        } catch (e) {
                            console.error('Radical Form JS Code: ', e);
                        }
                        return;
                    }
                    if (response.success) {
                        if (response.data[0][0]==="ok") {
                            if (form.querySelector(".rf-filenames-list")) {
                                form.querySelector(".rf-filenames-list").innerHTML="";
                            }
                            if (form.querySelector("input[name=needToSendFiles]")) {
                                form.querySelector("input[name=needToSendFiles]").remove();
                            }
                            releaseUploadGroup(form);
                            selfClass.refreshUniq();

                            //clear all fields of the form
                            selfClass.clearForm(form);

                            if (RadicalForm.Jivosite === "1") {
                                try {
                                    var result = jivo_api.sendOfflineMessage({
                                        "message": response.data[0][1]
                                    });

                                    if (result.result === "fail") {
                                        var a = {};
                                        try {
                                            jivo_api.sendMessage(a, response.data[0][1]);
                                        } catch (e) {
                                            console.error('Radical Form JS Code: ', e);
                                        }
                                    }

                                } catch (e) {
                                    console.error('Radical Form JS Code: ', e);
                                }

                            }
                            if (RadicalForm.Verbox === "1") {
                                try {
                                    Verbox("sendMessage", response.data[0][1]);
                                } catch (e) {
                                    console.error('Radical Form JS Code: ', e);
                                }
                             }

                            message = RadicalForm.AfterSend;
                            if (buttonPressed.dataset.rfCall === undefined) {
                                selfClass.runRfCall('2', message, buttonPressed);
                            } else {
                                rfCall = String(buttonPressed.dataset.rfCall);
                                for (var i = 0; i < rfCall.length; i++) {
                                    switch (rfCall[i]) {
                                        case "1":
                                            selfClass.runRfCall('1', message, buttonPressed);
                                            break;
                                        case "2":
                                            selfClass.runRfCall('2', message, buttonPressed);
                                            break;
                                        case "3":
                                            selfClass.runRfCall('3', message, buttonPressed);
                                    }

                                }
                            }

                        } else {
                            message = 'Error! ' + response.data[0];
                            try {
                                rfCall_9(message, buttonPressed);
                            } catch (e) {
                                console.error('Radical Form JS Code: ', e);
                            }
                        }

                    } else {
                        try {
                            selfClass.highlightFields(form, selfClass.getServerErrorFields(response));
                            rfCall_9((response.message),buttonPressed);
                        } catch (e) {
                            console.error('Radical Form JS Code: ', e);
                        }
                    }
                } else {
                    message = request.status
                        + (request.statusText ? ' ' + request.statusText : '');
                    console.error(
                        message
                        + (request.responseText ? '\n' + request.responseText : '')
                    );
                    try {
                        rfCall_9(message, buttonPressed);
                    } catch (e) {
                        console.error('Radical Form JS Code: ', e);
                    }
                }
            };

            var tokenPromise = selfClass.formTokenPromise || Promise.resolve(selfClass.formToken);
            tokenPromise.then(function(token) {
                if (token) {
                    AjaxFormData.set(token, '1');
                }

                return selfClass.getJsPermit();
            }).then(function(permit) {
                if (permit) {
                    AjaxFormData.append('rfJsPermit', permit);
                }

                var request = new XMLHttpRequest();
                request.open('POST', RadicalForm.Base + "/index.php?option=com_ajax&plugin=radicalform&group=system&format=json");
                request.onloadend = handleResponse;
                request.send(AjaxFormData);
            }).catch(function(error) {
                finishSubmission();

                var message = error && error.message ? error.message : RadicalForm.JsChallengeMessage;
                console.error('RadicalForm JavaScript challenge: ', message);

                try {
                    rfCall_9(message, buttonPressed);
                } catch (e) {
                    console.error('Radical Form JS Code: ', e);
                }
            });
        } else {
            submittingForms.delete(form);
        }
    };

    /**
     * Send file to server
     * @param e
     */
    this.fileSend = function(e) {
        var form = selfClass.closest(this, '.rf-form');
        var operation = form && fileOperations.get(form);
        if (!form || this.disabled || submittingForms.has(form) || (operation && operation.kind === 'delete')) {
            this.value = "";
            return;
        }

        if (!this.getAttribute("name")) {
            alert("RadicalForm: There is no 'name' attribute for rf-upload-button!\r\nFile can't uploaded. Please, add name attribute for file input tag.");
            return;
        }

        var textForUploadButton = this.parentNode.querySelector('.rf-upload-button-text'),
            previousTextForUploadButton = textForUploadButton ? textForUploadButton.innerHTML : "",
            disableUploadText = textForUploadButton && 'disabled' in textForUploadButton && !textForUploadButton.disabled,
            rf_filenames_list = form.querySelector('.rf-filenames-list') || document.createElement('div'),
            buttonPressed = this,
            files = Array.from(this.files),
            rfDelete="<svg aria-hidden=\"true\" focusable=\"false\" height=\"16\" viewBox=\"0 0 512 512\" width=\"16\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M256 0C114.836 0 0 114.836 0 256s114.836 256 256 256 256-114.836 256-256S397.164 0 256 0zm0 0\" fill=\"" + RadicalForm.DeleteBackground + "\"/><path d=\"M350.273 320.105c8.34 8.344 8.34 21.825 0 30.168a21.275 21.275 0 01-15.086 6.25c-5.46 0-10.921-2.09-15.082-6.25L256 286.164l-64.105 64.11a21.273 21.273 0 01-15.083 6.25 21.275 21.275 0 01-15.085-6.25c-8.34-8.344-8.34-21.825 0-30.169L225.836 256l-64.11-64.105c-8.34-8.344-8.34-21.825 0-30.168 8.344-8.34 21.825-8.34 30.169 0L256 225.836l64.105-64.11c8.344-8.34 21.825-8.34 30.168 0 8.34 8.344 8.34 21.825 0 30.169L286.164 256zm0 0\" fill=\""  +RadicalForm.DeleteColor + "\"/></svg>";

        var reservedFieldNames = selfClass.getReservedFieldNames(form);

        if (reservedFieldNames.length) {
            selfClass.showReservedFieldError(form, reservedFieldNames, this);
            this.value = "";
            return;
        }

        if (!files.length) {
            return;
        }

        if (RadicalForm.UploadEnabled != 1) {
            if (form.querySelector('.rf-filenames-list')) {
                rf_filenames_list.innerHTML = "<div class='" + selfClass.error_file_classes.join(' ') + "'>" + RadicalForm.UploadDisabled + "</div>";
            }
            buttonPressed.value = "";
            return;
        }

        var uploadGroup = uploadGroups.get(form);
        if (!uploadGroup) {
            uploadGroup = {uniq: selfClass.uniq};
            uploadGroups.set(form, uploadGroup);
        }
        var finishFileOperation = beginFileOperation(form, 'upload');

        var clearFileError = function() {
            var el=rf_filenames_list.querySelector("." + selfClass.error_file_classes.join('.'));

            while (el) {
                el.parentNode.removeChild(el);
                el=rf_filenames_list.querySelector("." + selfClass.error_file_classes.join('.'));
            }
        };

        var showFileSizeError = function() {
            rf_filenames_list.insertAdjacentHTML('beforeend',"<div class='" + selfClass.error_file_classes.join(' ') + "'>" + RadicalForm.ErrorMax + "</div>"); // size is more than limit
        };

        var uploadFile = function(file, done) {
            var formData = new FormData();

            formData.append(buttonPressed.name, file);

            if (file.size < RadicalForm.MaxSize) {
                formData.append("uniq", uploadGroup.uniq);
                if (selfClass.formToken) {
                    formData.append(selfClass.formToken, "1");
                }

                var request = new XMLHttpRequest(),
                    requestUrl = RadicalForm.Base + '/index.php?option=com_ajax&plugin=radicalform&format=json&group=system&file=1&size=' + file.size;

                request.open('POST', requestUrl);
                request.onloadend = done;
                request.onreadystatechange = function () {
                    if (this.readyState === 4 && this.status === 200) {
                        var response = false;
                        try {
                            response = JSON.parse(this.response);
                            var uploadedFile = response && response.data && response.data[0];
                            if (!response || typeof response.success !== 'boolean'
                                || (response.success && (!uploadedFile || (typeof uploadedFile.error !== 'string'
                                    && (typeof uploadedFile.name !== 'string' || typeof uploadedFile.key !== 'string'))))) {
                                throw new Error('Unexpected Joomla response.');
                            }
                        } catch (e) {
                            console.error(
                                'Invalid server response. Expected JSON.\nResponse code: '
                                + request.status
                                + (request.statusText ? ' ' + request.statusText : '')
                                + '\n' + e.message
                                + (request.responseText ? '\n' + request.responseText : '')
                            );
                            rf_filenames_list.insertAdjacentHTML('beforeend', "<div class='" + selfClass.error_file_classes.join(' ') + "'>Unknown Error. See Console.</div>");
                            response = false;
                            return;
                        }
                        if (response.success) {
                            if (typeof uploadedFile.error === 'string') {
                                rf_filenames_list.insertAdjacentHTML('beforeend', "<div class='" + selfClass.error_file_classes.join(' ') + "'>" + uploadedFile.error + "</div>");
                            } else {
                                if (rf_filenames_list.textContent.trim() === "") {
                                    rf_filenames_list.insertAdjacentHTML('beforeend', "<div>" + RadicalForm.thisFilesWillBeSend + "</div>");
                                }
                                if(!form.querySelector("input[name=needToSendFiles]")) {
                                    form.insertAdjacentHTML('beforeend', '<input type="hidden" name="needToSendFiles" value="1" />');
                                }
                                var existingRow = Array.from(rf_filenames_list.querySelectorAll('div[data-name]')).some(function(row) {
                                    var name = row.querySelector('span');
                                    return row.dataset.name === uploadedFile.key && name && name.textContent === uploadedFile.name;
                                });
                                if (!existingRow) {
                                    var row = document.createElement('div');
                                    var name = document.createElement('span');
                                    var deleteButton = document.createElement('button');
                                    row.dataset.name = uploadedFile.key;
                                    name.textContent = uploadedFile.name;
                                    row.appendChild(name);
                                    deleteButton.type = 'button';
                                    deleteButton.className = 'rf-button-delete';
                                    deleteButton.disabled = true;
                                    deleteButton.setAttribute('aria-label', RadicalForm.DeleteFile + ': ' + uploadedFile.name);
                                    deleteButton.setAttribute('style', 'border:0;padding:0;background:none;line-height:0;vertical-align:middle');
                                    deleteButton.innerHTML = rfDelete;
                                    fileOperations.get(form).controls.push(deleteButton);
                                    row.appendChild(document.createTextNode('\u00a0'));
                                    row.appendChild(deleteButton);
                                    rf_filenames_list.appendChild(row);
                                }
                            }

                        } else {
                            rf_filenames_list.insertAdjacentHTML('beforeend', "<div>" + response.message + "</div>");
                        }
                    } else if (this.readyState === 4 && this.status !== 200) {
                        try {
                            rfCall_9(
                                request.status
                                + (request.statusText ? ' ' + request.statusText : ''),
                                buttonPressed
                            );
                        } catch (e) {
                            console.error('Radical Form JS Code: ', e);
                        }
                        console.error(
                            request.status
                            + (request.statusText ? ' ' + request.statusText : '')
                            + (request.responseText ? '\n' + request.responseText : '')
                        );
                    }
                };
                request.send(formData);

            } else {
                showFileSizeError();
                done();
            }
        };

        var finishUpload = function() {
            if (textForUploadButton) {
                textForUploadButton.innerHTML = previousTextForUploadButton;
            }
            if (disableUploadText) {
                textForUploadButton.disabled = false;
            }

            buttonPressed.value = "";
            finishFileOperation();
        };

        var uploadFiles = function(index) {
            if (index >= files.length) {
                finishUpload();
                return;
            }

            try {
                uploadFile(files[index], function() {
                    uploadFiles(index + 1);
                });
            } catch (error) {
                finishUpload();
                console.error('RadicalForm upload request: ', error);
            }
        };

        if(textForUploadButton) {
            textForUploadButton.innerHTML = RadicalForm.waitingForUpload;
        }
        if (disableUploadText) {
            textForUploadButton.disabled = true;
        }

        clearFileError();
        uploadFiles(0);

    };

    this.on = function (el, selector, event, cb) {
        el.addEventListener(event, function (e) {
            for (var target = e.target; target && target != this; target = target.parentNode) {
                var matchesSelector = target.matches || target.webkitMatchesSelector || target.mozMatchesSelector || target.msMatchesSelector;
                if (matchesSelector.call(target, selector)) {
                    cb.call(target, e);
                    break;
                }
            }
        }, false);
    };

    this.closest =  function(el, selector) {
        var matchesSelector = el.matches || el.webkitMatchesSelector || el.mozMatchesSelector || el.msMatchesSelector;

        while (el) {
            if (matchesSelector.call(el, selector)) {
                return el;
            } else {
                el = el.parentElement;
            }
        }
        return null;
    }

    // clear the form after sending
    this.clearForm = function (formToClear) {

        var elements = Array.from(formToClear.querySelectorAll('input[name], select[name], textarea[name]'));


        for(var i=0; i<elements.length; i++) {

            switch(elements[i].type.toLowerCase()) {

                case "radio":
                case "checkbox":
                    if (elements[i].checked) {
                        elements[i].checked = false;
                    }
                    break;

                case "select-one":
                case "select-multiple":
                    elements[i].selectedIndex = -1;
                    break;

                case undefined:
                case "file":
                case "submit":
                case "reset":
                case "button":
                case "hidden":
                    break;

                default:
                    elements[i].value = "";
                    break;
            }
        }
    }
    this.showTime = function () {
        var monthsArr = ["01", "02", "03", "04", "05", "06",
            "07", "08", "09", "10", "11", "12"];
        var dateObj = new Date();
        var year = dateObj.getFullYear();
        var month = dateObj.getMonth();
        var numDay = dateObj.getDate();
        var hour = dateObj.getHours();
        var minute = dateObj.getMinutes();
        var second = dateObj.getSeconds();

        if (minute < 10) minute = "0" + minute;

        if (second < 10) second = "0" + second;

        return hour + ":" + minute + ":" + second + ", " + numDay + "." + monthsArr[month]
            + "." + year;
    }
    // This function is for RadicalForm Elements Steps
    this.nextStep = function (el,targetStep,animationStep,previous) {

        // targetStep - это путь от родительского класса к обрамляющему фрейму шага, которое гасится атрибутом hidden при переключении шагов
        // el - это текущая кнопка "далее"

        var step = selfClass.closest(el,targetStep); // это путь текущему фрейму шага, который все обрамляет
        var needReturn = false; // признак нарушения заполненности полей
        var elementsArray = document.querySelectorAll(targetStep + " .rf-next"); // список всех кнопок квиза
        var currentButtonNext = step.querySelector(".rf-next"); // ищем текущую кнопку Next, так как при нажатии на Previous мы должны работать с кнопками Next
        var currentIndex = Array.from(elementsArray).indexOf(currentButtonNext);
        // Если элемент не найден или является последним в массиве и при этому не является кнопкой назад
        if(!previous) {
            if (currentIndex === -1 || currentIndex === elementsArray.length - 1 ) {
                console.log("Last element reached ");
                return null; // то ничего не делаем и возвращаемся
            }
            var nextEl = elementsArray[currentIndex + 1]; // следующая кнопка далее
            var nextStep = selfClass.closest(nextEl, targetStep);
        }

        var previousEl = null;
        var previousStep = null;
        if (currentIndex > 0) {
            previousEl = elementsArray[currentIndex - 1]; // предыдущая кнопка далее
            previousStep = selfClass.closest(previousEl, targetStep);
        }

        if(previous) {
            // we go to the previous step, so we need to remove all events from button next of the previous step
            var elementForClone = previousEl,
                elementCloned = elementForClone.cloneNode(true);

            elementForClone.parentNode.replaceChild(elementCloned, elementForClone);
            nextStep = previousStep;
        } else {
            RadicalForm.FormFields = [];
            [].forEach.call(step.querySelectorAll("[name]"), function (el) {
                // remove danger classes so they can be animated later
                selfClass.danger_classes.forEach(function (item) {
                    el.classList.remove(item);
                });
                // let's see the fields of form to check for validity and required
                if ((el.classList.contains('required') && el.value.trim() === "") ||
                    (el.classList.contains('required') && (!el.checked) && (el.type === "checkbox")) ||
                    (!el.checkValidity())) {
                    RadicalForm.FormFields.push(el);
                    needReturn = true;
                }
            });

            setTimeout(function () {
                for (var i = 0; i < RadicalForm.FormFields.length; i++) {
                    selfClass.danger_classes.forEach(function (item) {
                        RadicalForm.FormFields[i].classList.add(item);
                    })
                }
            }, 70);
        }

        if (!needReturn) {
            if(animationStep) {
                UIkit.toggle(el,{
                    target: [step, nextStep],
                    animation: animationStep
                }).toggle();
            } else {
                UIkit.toggle(el,{
                    target: [step, nextStep]
                }).toggle();
            }
        }

    }
};

ready(function () {
    RadicalForm.RadicalFormClass = new RadicalFormClass;
    RadicalForm.RadicalFormClass.init();
});
