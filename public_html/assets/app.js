/*
 * LUNA OD6 booking system - browser behaviour.
 *
 * The calendar mirrors the booking rules so the UI is pleasant to use, but the
 * server enforces them. Nothing here is a security control: every write is
 * re-checked server-side, including who owns the booking being changed.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        wireConfirmations();
        wireAutoSubmit();
        wireDateEchoes();
        wireInviteLink();
        initCalendar();
    });

    /* Any element with data-confirm asks before submitting. */
    function wireConfirmations() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            var handler = function (event) {
                if (!window.confirm(el.dataset.confirm)) {
                    event.preventDefault();
                }
            };

            if (el.tagName === 'FORM') {
                el.addEventListener('submit', handler);
            } else {
                el.addEventListener('click', handler);
            }
        });
    }

    /*
     * Pickers that submit their form as soon as the choice changes. The button
     * beside them is what works when this script has not run; the policy allows
     * no inline handler, so the wiring lives here.
     */
    function wireAutoSubmit() {
        document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
            el.addEventListener('change', function () {
                if (el.form) {
                    el.form.submit();
                }
            });
        });
    }

    /*
     * A date input displays itself in the browser's locale, which may well put
     * the month first. Each one names an element to spell its value out in,
     * day first, so what was picked is never in doubt.
     */
    function wireDateEchoes() {
        document.querySelectorAll('input[type="date"][data-echo]').forEach(function (input) {
            var echo = document.getElementById(input.dataset.echo);

            if (!echo) {
                return;
            }

            var update = function () {
                echo.textContent = input.value ? longDate(input.value) : '';
            };

            input.addEventListener('change', update);
            update();
        });
    }

    /* Select the whole invite link on focus, so it can be copied in one go. */
    function wireInviteLink() {
        var field = document.querySelector('.invite-link');
        if (field) {
            field.addEventListener('focus', function () { field.select(); });
            field.focus();
        }
    }

    function initCalendar() {
        var el = document.getElementById('calendar');
        if (!el || typeof FullCalendar === 'undefined') {
            return;
        }

        var cfg = JSON.parse(el.dataset.config);
        var feedUrl = el.dataset.feed;
        var csrf = el.dataset.csrf;

        var dialog = document.getElementById('booking-dialog');
        var form = document.getElementById('booking-form');
        var titleEl = document.getElementById('booking-dialog-title');
        var errorEl = document.getElementById('booking-dialog-error');
        var ownerEl = document.getElementById('booking-dialog-owner');
        var start = whenField('start');
        var end = whenField('end');
        var purposeEl = document.getElementById('booking-purpose');
        var ownerNetidEl = document.getElementById('booking-owner');
        var saveBtn = document.getElementById('booking-save');
        var deleteBtn = document.getElementById('booking-delete');
        var closeBtn = document.getElementById('booking-close');

        /* The booking currently open in the dialog, or null when creating. */
        var editing = null;

        /*
         * A date input plus a time dropdown the application fills itself, so
         * the time always reads as 24h whatever the browser's locale is. The
         * date input keeps its native picker - its value is ISO either way -
         * and the echo beneath spells the date out day-first, so a widget
         * showing 09/14/2026 is never ambiguous.
         */
        function whenField(name) {
            var dateEl = document.getElementById('booking-' + name + '-date');
            var timeEl = document.getElementById('booking-' + name + '-time');
            var echoEl = document.getElementById('booking-' + name + '-echo');

            fillTimes(timeEl, cfg.slotMinutes);

            var field = {
                date: dateEl,
                time: timeEl,
                /* The combined local value, as the API expects it. */
                value: function () {
                    return dateEl.value && timeEl.value ? dateEl.value + 'T' + timeEl.value : '';
                },
                set: function (date) {
                    dateEl.value = isoDate(date);
                    timeEl.value = nearestOption(timeEl, pad2(date.getHours()) + ':' + pad2(date.getMinutes()));
                    field.refresh();
                },
                refresh: function () {
                    echoEl.textContent = dateEl.value ? longDate(dateEl.value) : '';
                },
                readOnly: function (readOnly) {
                    dateEl.readOnly = readOnly;
                    timeEl.disabled = readOnly;
                }
            };

            dateEl.addEventListener('change', field.refresh);

            return field;
        }

        var calendar = new FullCalendar.Calendar(el, {
            initialView: 'timeGridWeek',
            /* 'local' rather than the named lab timezone: that would need an
               extra plugin, and the server already sends offsets. Bare times
               typed into the form are read by the server in the lab timezone. */
            timeZone: 'local',
            firstDay: 1,
            nowIndicator: true,
            allDaySlot: false,
            height: 'auto',
            slotDuration: minutes(cfg.slotMinutes),
            snapDuration: minutes(cfg.slotMinutes),
            slotMinTime: pad(cfg.openTime) + ':00',
            slotMaxTime: closingTime(cfg.closeTime),
            businessHours: {
                daysOfWeek: cfg.openDays,
                startTime: cfg.openTime,
                endTime: cfg.closeTime
            },
            /* Admins may need days the machine is normally closed; everyone
               else is only shown the days they can actually book. */
            hiddenDays: cfg.isAdmin ? [] : hiddenDays(cfg.openDays),
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'timeGridWeek,timeGridDay,listWeek'
            },
            buttonText: { today: 'Today', week: 'Week', day: 'Day', list: 'List' },
            selectable: true,
            selectMirror: true,
            selectConstraint: cfg.isAdmin ? undefined : 'businessHours',
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            /* Dates are spelled out day first. The bundled library carries no
               locale data, so its own defaults would read as American; these
               callbacks decide the wording rather than the viewer's browser. */
            titleFormat: function (arg) { return rangeLabel(arg.start, arg.end); },
            dayHeaderFormat: function (arg) {
                return WEEKDAYS[arg.date.marker.getUTCDay()] + ' ' + arg.date.day + ' ' +
                    MONTHS[arg.date.month];
            },
            listDayFormat: function (arg) {
                return arg.date.day + ' ' + MONTHS[arg.date.month] + ' ' + arg.date.year;
            },
            events: loadEvents,
            select: function (info) {
                openCreate(info.start, info.end);
                calendar.unselect();
            },
            eventClick: function (info) {
                openEvent(info.event);
            },
            eventDrop: function (info) { moveEvent(info); },
            eventResize: function (info) { moveEvent(info); }
        });

        calendar.render();

        /* ------------------------------------------------------------ data */

        function loadEvents(info, success, failure) {
            var url = feedUrl + '?resource=' + encodeURIComponent(cfg.resourceId) +
                '&from=' + encodeURIComponent(info.startStr) +
                '&to=' + encodeURIComponent(info.endStr);

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(readJson)
                .then(success)
                .catch(function (err) {
                    failure(err);
                    alert(err.message || 'The calendar could not be loaded.');
                });
        }

        function readJson(response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    var message = body && body.error ? body.error : 'Request failed.';
                    throw new Error(message);
                }
                return body;
            }, function () {
                throw new Error(response.status === 401
                    ? 'Your session has expired. Please reload the page and sign in again.'
                    : 'The server sent an unreadable response.');
            });
        }

        function post(url, payload) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify(payload)
            }).then(readJson);
        }

        /* ---------------------------------------------------------- dialog */

        function openCreate(startsAt, endsAt) {
            editing = null;
            titleEl.textContent = 'Book the machine';
            start.set(startsAt);
            end.set(endsAt);
            purposeEl.value = '';
            if (ownerNetidEl) { ownerNetidEl.value = ''; }
            ownerEl.hidden = true;
            deleteBtn.hidden = true;
            showError(null);
            open();
        }

        function openEvent(event) {
            var props = event.extendedProps || {};

            if (!props.canModify) {
                /* Someone else's booking: show who has the machine, nothing more. */
                editing = null;
                titleEl.textContent = 'Booked';
                ownerEl.textContent = props.owner + ' has the machine from ' +
                    timeOf(event.start) + ' to ' + timeOf(event.end) + '.';
                ownerEl.hidden = false;
                start.set(event.start);
                end.set(event.end);
                purposeEl.value = '';
                setReadOnly(true);
                deleteBtn.hidden = true;
                saveBtn.hidden = true;
                showError(null);
                open();
                return;
            }

            editing = event;
            titleEl.textContent = props.own ? 'Your booking' : 'Booking for ' + props.owner;
            ownerEl.hidden = props.own;
            if (!props.own) {
                ownerEl.textContent = 'Owner: ' + props.owner +
                    (props.ownerNetid ? ' (' + props.ownerNetid + ')' : '');
            }
            start.set(event.start);
            end.set(event.end);
            purposeEl.value = props.purpose || '';
            if (ownerNetidEl) { ownerNetidEl.value = ''; }
            setReadOnly(false);
            saveBtn.hidden = false;
            deleteBtn.hidden = false;
            showError(null);
            open();
        }

        function open() {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        }

        function close() {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
            setReadOnly(false);
            saveBtn.hidden = false;
        }

        function setReadOnly(readOnly) {
            start.readOnly(readOnly);
            end.readOnly(readOnly);
            purposeEl.readOnly = readOnly;
            if (ownerNetidEl) { ownerNetidEl.disabled = readOnly; }
        }

        function showError(message) {
            errorEl.hidden = !message;
            errorEl.textContent = message || '';
        }

        closeBtn.addEventListener('click', close);
        dialog.addEventListener('cancel', function () { setReadOnly(false); saveBtn.hidden = false; });

        saveBtn.addEventListener('click', function () {
            if (!start.value() || !end.value()) {
                showError('Please give a start and an end time.');
                return;
            }

            var payload = {
                resource: cfg.resourceId,
                start: start.value(),
                end: end.value(),
                purpose: purposeEl.value
            };

            if (ownerNetidEl && ownerNetidEl.value) {
                payload.owner_netid = ownerNetidEl.value;
            }

            var url = editing ? feedUrl + '/' + editing.id : feedUrl;

            saveBtn.disabled = true;
            post(url, payload)
                .then(function () {
                    close();
                    calendar.refetchEvents();
                })
                .catch(function (err) { showError(err.message); })
                .finally(function () { saveBtn.disabled = false; });
        });

        deleteBtn.addEventListener('click', function () {
            if (!editing || !window.confirm('Cancel this booking?')) {
                return;
            }

            deleteBtn.disabled = true;
            post(feedUrl + '/' + editing.id + '/cancel', {})
                .then(function () {
                    close();
                    calendar.refetchEvents();
                })
                .catch(function (err) { showError(err.message); })
                .finally(function () { deleteBtn.disabled = false; });
        });

        /* Dragging or resizing writes straight away; a refusal snaps back. */
        function moveEvent(info) {
            post(feedUrl + '/' + info.event.id, {
                start: info.event.start.toISOString(),
                end: info.event.end.toISOString(),
                purpose: info.event.extendedProps.purpose || ''
            }).then(function () {
                calendar.refetchEvents();
            }).catch(function (err) {
                info.revert();
                alert(err.message);
            });
        }
    }

    /* ---------------------------------------------------------- helpers */

    function minutes(count) {
        var hours = Math.floor(count / 60);
        var rest = count % 60;
        return pad2(hours) + ':' + pad2(rest) + ':00';
    }

    function pad(time) { return /^\d:/.test(time) ? '0' + time : time; }

    function pad2(value) { return (value < 10 ? '0' : '') + value; }

    /* 24:00 is how FullCalendar spells "to the end of the day". */
    function closingTime(closeTime) {
        return closeTime === '00:00' ? '24:00:00' : pad(closeTime) + ':00';
    }

    function hiddenDays(openDays) {
        var hidden = [];
        for (var day = 0; day <= 6; day++) {
            /* openDays uses ISO numbering (Mon=1..Sun=7); FullCalendar uses
               Sun=0..Sat=6. */
            var iso = day === 0 ? 7 : day;
            if (openDays.indexOf(iso) === -1) {
                hidden.push(day);
            }
        }
        return hidden;
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /*
     * A calendar heading: "14 - 20 Sep 2026", collapsing whatever the two ends
     * have in common. FullCalendar hands the end as exclusive, so the last day
     * shown is the one before it.
     */
    function rangeLabel(startParts, endParts) {
        if (!endParts) {
            return startParts.day + ' ' + MONTHS[startParts.month] + ' ' + startParts.year;
        }

        var start = startParts.marker;
        /* The end is exclusive, and markers are UTC, so a day is always 24h. */
        var last = new Date(endParts.marker.getTime() - 86400000);

        var startDay = start.getUTCDate();
        var lastDay = last.getUTCDate();
        var startMonth = MONTHS[start.getUTCMonth()];
        var lastMonth = MONTHS[last.getUTCMonth()];
        var startYear = start.getUTCFullYear();
        var lastYear = last.getUTCFullYear();

        if (startYear !== lastYear) {
            return startDay + ' ' + startMonth + ' ' + startYear + ' - ' +
                lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        if (startMonth !== lastMonth) {
            return startDay + ' ' + startMonth + ' - ' + lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        if (startDay !== lastDay) {
            return startDay + ' - ' + lastDay + ' ' + lastMonth + ' ' + lastYear;
        }

        return startDay + ' ' + lastMonth + ' ' + lastYear;
    }

    /* A Date as the value a date input expects. */
    function isoDate(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }

    /*
     * An ISO date spelled out day-first. The browser may render the date input
     * itself in any order it likes; this is what the reader goes by.
     */
    function longDate(iso) {
        var parts = iso.split('-');
        var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

        return WEEKDAYS[date.getDay()] + ' ' + Number(parts[2]) + ' ' +
            MONTHS[Number(parts[1]) - 1] + ' ' + parts[0];
    }

    /* Fill a dropdown with every time of day, at the booking slot's spacing. */
    function fillTimes(select, stepMinutes) {
        var step = Math.max(1, Math.min(24 * 60, stepMinutes || 30));
        var options = document.createDocumentFragment();

        for (var m = 0; m < 24 * 60; m += step) {
            var option = document.createElement('option');
            option.value = pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);
            option.textContent = option.value;
            options.appendChild(option);
        }

        select.replaceChildren(options);
    }

    /*
     * The option closest to a wanted time. A booking made before the admin
     * changed the slot length may not land on the current grid, and the server
     * would rather hear a valid time than an empty one.
     */
    function nearestOption(select, wanted) {
        var target = toMinutes(wanted);
        var best = null;
        var bestGap = Infinity;

        Array.prototype.forEach.call(select.options, function (option) {
            var gap = Math.abs(toMinutes(option.value) - target);
            if (gap < bestGap) {
                bestGap = gap;
                best = option.value;
            }
        });

        return best === null ? wanted : best;
    }

    function toMinutes(time) {
        var parts = time.split(':');

        return Number(parts[0]) * 60 + Number(parts[1]);
    }

    function timeOf(date) {
        return date ? pad2(date.getHours()) + ':' + pad2(date.getMinutes()) : '?';
    }
})();
