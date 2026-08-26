if (!window.Joomla) {
    throw new Error('Joomla API was not properly initialised');
}
function ready(fn) {
    if (document.readyState != 'loading'){
        fn();
    } else {
        document.addEventListener('DOMContentLoaded', fn);
    }
}
ready(function () {

    const table = document.querySelector(".reg-rules table");
    if(table) {
        table.classList.add("table-striped", "table-bordered");
    }
    const table1 = document.querySelector(".rf-telegram-chatid table");
    if(table1) {
        table1.classList.add("table-striped", "table-bordered");
    }
    const table2 = document.querySelector(".rf-max-recipients table");
    if(table2) {
        table2.classList.add("table-striped", "table-bordered");
    }

    function getUrlParams(url){
        var regex = /[?&]([^=#]+)=([^&#]*)/g,
            params = {},
            match;
        while(match = regex.exec(url)) {
            params[match[1]] = match[2];
        }
        return params;
    }

    var currentGetParams,
        page,
        log;
    currentGetParams = getUrlParams(location.search);

    var historyClear = document.querySelector("#historyclear");
    if(historyClear)
    {
        historyClear.addEventListener('click', async function (event) {
            historyClear.innerHTML = "Wait...";
            historyClear.disabled = true;
            if ('page' in currentGetParams) {
                page = currentGetParams.page;
            } else {
                page = "0";
            }
            if ('log' in currentGetParams) {
                log = currentGetParams.log;
            } else {
                log = "messages";
            }
            Joomla.request({
                url: "index.php?option=com_ajax&plugin=radicalform&format=json&group=system",
                method: "POST",
                data: "admin=2&page=" + encodeURIComponent(page) + "&log=" + encodeURIComponent(log) + "&" + encodeURIComponent(historyClear.dataset.token) + "=1",
                onSuccess: function (response, xhr){
                    // Тут делаем что-то с результатами
                    location.reload();
                },
                onError: function(xhr){
                    // Тут делаем что-то в случае ошибки запроса.
                    location.reload();
                }
            });

            /*              location.reload();

                          $.getJSON("index.php?option=com_ajax&plugin=radicalform&format=json&group=system&admin=2&page=" + page, function (data) {
                              location.reload();
                          });*/

            event.preventDefault();
        });
    }

    var numberClear = document.querySelector("#numberclear");
    if(numberClear)
    {
        // reset the numbering of forms sent
        numberClear.addEventListener('click', function (event) {
            numberClear.innerHTML  = "Wait...";
            numberClear.disabled = true;
            Joomla.request({
                url: "index.php?option=com_ajax&plugin=radicalform&format=json&group=system",
                method: "POST",
                data: "admin=3&" + encodeURIComponent(numberClear.dataset.token) + "=1",
                onSuccess: function (response, xhr){
                    // Тут делаем что-то с результатами
                    location.reload();
                },
                onError: function(xhr){
                    // Тут делаем что-то в случае ошибки запроса.
                    location.reload();
                }
            });
            /*
            $.getJSON("index.php?option=com_ajax&plugin=radicalform&format=json&group=system&admin=3", function (data) {
                location.reload();
            });*/
            event.preventDefault();
        });
    }

    [].forEach.call(document.querySelectorAll(".exportcsv"), function (exportCSV) {
        exportCSV.addEventListener('click', function () {
            var temp = exportCSV.innerHTML;
            exportCSV.innerHTML = "Wait...";
            exportCSV.classList.add("disabled");

            setTimeout(function () {
                exportCSV.innerHTML = temp;
                exportCSV.classList.remove("disabled");
            }, 3000)
        });
    });




//show the info about need to save parameters
    [].forEach.call(document.querySelectorAll('#attrib-list label.btn'), function (el) {
        el.addEventListener('click',function (e) {
            if(!document.querySelector("#attrib-list .alert.alert-info.hidden")) return;
            document.querySelector("#attrib-list .alert.alert-info.hidden").classList.remove("hidden");
        });
    });


    var radicalformCheckButton = document.querySelector("#radicalformcheck");
    if(radicalformCheckButton)
    {
        radicalformCheckButton.addEventListener('click', function (event) {
        var radicalformcheck=document.querySelector("#radicalformcheck"),
            temp = radicalformcheck.innerHTML;
        radicalformcheck.innerHTML="Wait...";
        radicalformcheck.disabled = true;


        var request = new XMLHttpRequest();
        request.open('POST', 'index.php?option=com_ajax&plugin=radicalform&format=json&group=system&admin=1', true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

        request.onload = function() {
            if (this.status >= 200 && this.status < 400) {
                // Success!
                var data = JSON.parse(this.response);
                if(data.data[0].ok) {
                    var output=data.data[0].chatids;
                    if(output.length>0) {
                        for(var i=0;i<output.length;i++) {
                            var found=false;
                            [].forEach.call(document.querySelectorAll('#attrib-advanced .rf-telegram-chatid tr td:first-child input'), function (el) {
                                if(el.value==output[i].chatID) {
                                    found=true;
                                }
                            })

                            if(!found) {
                                var event = document.createEvent('HTMLEvents');
                                event.initEvent('click', true, false);
                                document.querySelector("#attrib-advanced .rf-telegram-chatid thead .btn").dispatchEvent(event);

                                var lastString=document.querySelectorAll("#attrib-advanced .rf-telegram-chatid tr:last-child input");
                                lastString[0].value=output[i].chatID;
                                lastString[1].value=output[i].name;

                            }
                        }
                    } else {
                        Joomla.renderMessages({"warning":["There are no messages to bot"]},"#radicalformresult");
                    }


                } else {
                    Joomla.renderMessages({"danger":["<strong>Error code "+data.data[0].error_code+"</strong><br>" + data.data[0].description]},"#radicalformresult");


                }

            } else {
                // We reached our target server, but it returned an error
                Joomla.renderMessages({"danger":["<strong>Error</strong><br>" + this.response]},"#radicalformresult");

            }
            radicalformcheck.disabled = false;
            radicalformcheck.innerHTML = temp;
        };

        request.onerror = function() {
            // There was a connection error of some sort
            document.querySelector("#radicalformcheck").insertAdjacentHTML("afterend","<div class=\"alert alert-error input-xxlarge telegram-note\"><button type=\"button\" class=\"close\" data-dismiss=\"alert\">×</button><h4>Error </h4><span>Error connection</span></div>")

            radicalformcheck.disabled = false;
            radicalformcheck.innerHTML = temp;
        };

        request.send(encodeURIComponent(radicalformcheck.dataset.token) + '=1');

        event.preventDefault();
        });
    }

    var radicalformCheckMaxConnectionButton = document.querySelector("#radicalformcheckmaxconnection");
    if(radicalformCheckMaxConnectionButton)
    {
        radicalformCheckMaxConnectionButton.addEventListener('click', function (event) {
            var button = document.querySelector("#radicalformcheckmaxconnection"),
                temp = button.innerHTML,
                resultContainer = document.querySelector("#radicalformmaxconnectionresult");
            if(resultContainer) {
                resultContainer.innerHTML = "";
            }
            button.innerHTML="Wait...";
            button.disabled = true;

            var request = new XMLHttpRequest();
            request.open('POST', 'index.php?option=com_ajax&plugin=radicalform&format=json&group=system&admin=maxconnection', true);
            request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

            request.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    try {
                        var data = JSON.parse(this.response),
                            result = data.data[0],
                            messages = {};
                        messages[result.ok ? "success" : "danger"] = [result.message];
                        Joomla.renderMessages(messages,"#radicalformmaxconnectionresult");
                    } catch (error) {
                        Joomla.renderMessages({"danger":["<strong>Error</strong><br>" + this.response]},"#radicalformmaxconnectionresult");
                    }
                } else {
                    Joomla.renderMessages({"danger":["<strong>Error</strong><br>" + this.response]},"#radicalformmaxconnectionresult");
                }
                button.disabled = false;
                button.innerHTML = temp;
            };

            request.onerror = function() {
                Joomla.renderMessages({"danger":["<strong>Error</strong><br>Error connection"]},"#radicalformmaxconnectionresult");
                button.disabled = false;
                button.innerHTML = temp;
            };

            request.send(encodeURIComponent(button.dataset.token) + '=1');

            event.preventDefault();
        });
    }

    var radicalformCheckMaxButton = document.querySelector("#radicalformcheckmax");
    if(radicalformCheckMaxButton)
    {
        radicalformCheckMaxButton.addEventListener('click', function (event) {
            var radicalformcheckmax=document.querySelector("#radicalformcheckmax"),
                temp = radicalformcheckmax.innerHTML,
                resultContainer = document.querySelector("#radicalformmaxresult");
            if(resultContainer) {
                resultContainer.innerHTML = "";
            }
            radicalformcheckmax.innerHTML="Wait...";
            radicalformcheckmax.disabled = true;

            var request = new XMLHttpRequest();
            request.open('POST', 'index.php?option=com_ajax&plugin=radicalform&format=json&group=system&admin=maxupdates', true);
            request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

            request.onload = function() {
                if (this.status >= 200 && this.status < 400) {
                    var data = JSON.parse(this.response);
                    if(data.data[0].ok) {
                        var output=data.data[0].recipients;
                        if(output.length>0) {
                            for(var i=0;i<output.length;i++) {
                                var found=false;
                                [].forEach.call(document.querySelectorAll('#attrib-advanced .rf-max-recipients tr td:first-child input'), function (el) {
                                    if(el.value==output[i].id) {
                                        found=true;
                                    }
                                })

                                if(!found) {
                                    var event = document.createEvent('HTMLEvents');
                                    event.initEvent('click', true, false);
                                    document.querySelector("#attrib-advanced .rf-max-recipients thead .btn").dispatchEvent(event);

                                    var lastString=document.querySelectorAll("#attrib-advanced .rf-max-recipients tr:last-child input, #attrib-advanced .rf-max-recipients tr:last-child select");
                                    lastString[0].value=output[i].id;
                                    lastString[1].value=output[i].type;
                                    lastString[2].value=output[i].name;
                                }
                            }
                        } else {
                            Joomla.renderMessages({"warning":[data.data[0].message || "There are no messages to MAX bot"]},"#radicalformmaxresult");
                        }
                    } else {
                        Joomla.renderMessages({"danger":["<strong>Error</strong><br>" + JSON.stringify(data.data[0])]},"#radicalformmaxresult");
                    }
                } else {
                    Joomla.renderMessages({"danger":["<strong>Error</strong><br>" + this.response]},"#radicalformmaxresult");
                }
                radicalformcheckmax.disabled = false;
                radicalformcheckmax.innerHTML = temp;
            };

            request.onerror = function() {
                document.querySelector("#radicalformcheckmax").insertAdjacentHTML("afterend","<div class=\"alert alert-error input-xxlarge max-note\"><button type=\"button\" class=\"close\" data-dismiss=\"alert\">×</button><h4>Error </h4><span>Error connection</span></div>")

                radicalformcheckmax.disabled = false;
                radicalformcheckmax.innerHTML = temp;
            };

            request.send(encodeURIComponent(radicalformcheckmax.dataset.token) + '=1');

            event.preventDefault();
        });
    }

});
