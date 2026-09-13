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
        var startEl = document.getElementById('booking-start');
        var endEl = document.getElementById('booking-end');
        var purposeEl = document.getElementById('booking-purpose');
        var ownerNetidEl = document.getElementById('booking-owner');
        var saveBtn = document.getElementById('booking-save');
        var deleteBtn = document.getElementById('booking-delete');
        var closeBtn = document.getElementById('booking-close');

        /* The booking currently open in the dialog, or null when creating. */
        var editing = null;

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
            var url = feedUrl + '?from=' + encodeURIComponent(info.startStr) +
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

        function openCreate(start, end) {
            editing = null;
            titleEl.textContent = 'Book the machine';
            startEl.value = toLocalInput(start);
            endEl.value = toLocalInput(end);
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
                startEl.value = toLocalInput(event.start);
                endEl.value = toLocalInput(event.end);
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
            startEl.value = toLocalInput(event.start);
            endEl.value = toLocalInput(event.end);
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
            [startEl, endEl, purposeEl].forEach(function (field) {
                field.readOnly = readOnly;
            });
            if (ownerNetidEl) { ownerNetidEl.disabled = readOnly; }
        }

        function showError(message) {
            errorEl.hidden = !message;
            errorEl.textContent = message || '';
        }

        closeBtn.addEventListener('click', close);
        dialog.addEventListener('cancel', function () { setReadOnly(false); saveBtn.hidden = false; });

        saveBtn.addEventListener('click', function () {
            if (!startEl.value || !endEl.value) {
                showError('Please give a start and an end time.');
                return;
            }

            var payload = {
                start: startEl.value,
                end: endEl.value,
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

    /* A Date as the value a datetime-local input expects. */
    function toLocalInput(date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()) +
            'T' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    }

    function timeOf(date) {
        return date ? pad2(date.getHours()) + ':' + pad2(date.getMinutes()) : '?';
    }
})();
