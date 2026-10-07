/**
 * NUIS Lipa Certificate of Registration (COR) Parser
 * Extracts student info, subjects, and schedules to compute free-time availability.
 */
(function(window) {
    'use strict';

    if (window.pdfjsLib) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/js/pdf.worker.min.js';
    }

    const DAY_MAP = {
        'M': 'Monday',
        'T': 'Tuesday',
        'W': 'Wednesday',
        'TH': 'Thursday',
        'F': 'Friday',
        'SA': 'Saturday',
        'SU': 'Sunday'
    };

    function parseTimeMinutes(str) {
        if (!str) return null;
        const m = str.trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i);
        if (!m) return null;
        let h = parseInt(m[1], 10);
        const min = parseInt(m[2], 10);
        const ampm = m[3] ? m[3].toUpperCase() : null;
        if (ampm === 'PM' && h < 12) h += 12;
        if (ampm === 'AM' && h === 12) h = 0;
        return h * 60 + min;
    }

    function minutesTo24H(totalMinutes) {
        const h = String(Math.floor(totalMinutes / 60)).padStart(2, '0');
        const m = String(totalMinutes % 60).padStart(2, '0');
        return `${h}:${m}`;
    }

    function minutesTo12H(totalMinutes) {
        let h = Math.floor(totalMinutes / 60);
        const m = String(totalMinutes % 60).padStart(2, '0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        if (h === 0) h = 12;
        return `${h}:${m} ${ampm}`;
    }

    function parseDayCodes(rawStr) {
        if (!rawStr) return [];
        const s = rawStr.toUpperCase().trim();
        if (s === 'MTH') return ['Monday', 'Thursday'];
        if (s === 'TF') return ['Tuesday', 'Friday'];
        if (s === 'WS') return ['Wednesday', 'Saturday'];
        if (s === 'MWF') return ['Monday', 'Wednesday', 'Friday'];
        if (s === 'TTH') return ['Tuesday', 'Thursday'];
        if (s === 'TTS' || s === 'TTHS') return ['Tuesday', 'Thursday', 'Saturday'];

        const days = [];
        let i = 0;
        while (i < s.length) {
            const sub2 = s.substr(i, 2);
            if (sub2 === 'TH') {
                days.push('Thursday');
                i += 2;
            } else if (sub2 === 'SA') {
                days.push('Saturday');
                i += 2;
            } else if (sub2 === 'SU') {
                days.push('Sunday');
                i += 2;
            } else {
                const c = s[i];
                if (DAY_MAP[c]) {
                    days.push(DAY_MAP[c]);
                }
                i += 1;
            }
        }
        return days;
    }

    async function parseNuisCorPdf(fileOrArrayBuffer) {
        if (!window.pdfjsLib) {
            throw new Error('PDF.js library is not loaded.');
        }

        let data;
        if (fileOrArrayBuffer instanceof ArrayBuffer) {
            data = new Uint8Array(fileOrArrayBuffer);
        } else if (fileOrArrayBuffer instanceof Uint8Array) {
            data = fileOrArrayBuffer;
        } else if (fileOrArrayBuffer instanceof Blob || fileOrArrayBuffer instanceof File) {
            const buffer = await fileOrArrayBuffer.arrayBuffer();
            data = new Uint8Array(buffer);
        } else {
            throw new Error('Invalid file input for PDF parser.');
        }

        const doc = await window.pdfjsLib.getDocument({ data }).promise;
        const page = await doc.getPage(1);
        const textContent = await page.getTextContent();
        const items = textContent.items;

        // Group into lines ordered top-to-bottom, left-to-right
        const sortedItems = [...items].sort((a, b) => {
            if (Math.abs(a.transform[5] - b.transform[5]) > 3) {
                return b.transform[5] - a.transform[5];
            }
            return a.transform[4] - b.transform[4];
        });

        const lines = [];
        let currentLine = [];
        let lastY = null;

        for (const item of sortedItems) {
            const y = Math.round(item.transform[5]);
            if (lastY === null || Math.abs(y - lastY) > 3) {
                if (currentLine.length > 0) {
                    lines.push(currentLine.join(' '));
                    currentLine = [];
                }
                lastY = y;
            }
            const s = item.str.trim();
            if (s) currentLine.push(s);
        }
        if (currentLine.length > 0) {
            lines.push(currentLine.join(' '));
        }

        const fullText = lines.join('\n');

        // Extract metadata
        let studentId = '';
        let studentName = '';
        let schoolYear = '';
        let term = '';
        let course = '';

        const idMatch = fullText.match(/Student ID:\s*([0-9\-]+)/i);
        if (idMatch) studentId = idMatch[1].trim();

        const nameMatch = fullText.match(/Name:\s*([^\n\r]+?)(?=\s*Term:|\s*School Year:|$)/i);
        if (nameMatch) studentName = nameMatch[1].trim();

        const syMatch = fullText.match(/School Year:\s*([0-9\s\-]+)/i);
        if (syMatch) schoolYear = syMatch[1].trim();

        const termMatch = fullText.match(/Term:\s*([0-9]+)/i);
        if (termMatch) term = termMatch[1].trim();

        const courseMatch = fullText.match(/Course:\s*([^\n\r]+)/i);
        if (courseMatch) course = courseMatch[1].trim();

        // Extract subjects and schedule
        // NUIS schedule time format: 05:00PM - 07:00PM or 11:00AM - 02:20PM
        const scheduleRegex = /(MTH|TF|MWF|TTH|TH|SA|SU|M|T|W|F)\s+(\d{1,2}:\d{2}\s*(?:AM|PM))\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM))/gi;

        const classSchedules = [];
        let inSubjectSection = false;
        let currentSubjectCode = '';

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            if (line.includes('SUBJECT') && line.includes('SCHEDULE')) {
                inSubjectSection = true;
                continue;
            }
            if (inSubjectSection && (line.includes('TOTAL UNITS') || line.includes('TUITION FEE') || line.includes('MISCELLANEOUS'))) {
                inSubjectSection = false;
                break;
            }

            if (inSubjectSection) {
                // NUIS prints continuation rows with an em dash instead of
                // repeating the subject code. Keep the last subject for those
                // rows so every day/time entry retains its subject.
                const firstToken = line.split(/\s+/)[0] || '';
                if (/^[A-Z0-9]{5,10}$/i.test(firstToken)) {
                    currentSubjectCode = firstToken;
                }

                let match;
                while ((match = scheduleRegex.exec(line)) !== null) {
                    const dayCode = match[1];
                    const startTimeStr = match[2];
                    const endTimeStr = match[3];
                    const startMin = parseTimeMinutes(startTimeStr);
                    const endMin = parseTimeMinutes(endTimeStr);
                    const days = parseDayCodes(dayCode);

                    classSchedules.push({
                        line: line,
                        subjectCode: currentSubjectCode,
                        dayCode: dayCode,
                        days: days,
                        startTimeStr: startTimeStr,
                        endTimeStr: endTimeStr,
                        startMin: startMin,
                        endMin: endMin,
                        displayTime: `${startTimeStr} - ${endTimeStr}`
                    });
                }
            }
        }

        // Some PDF text layers split the subject token into a separate text
        // item, so the continuation-row lookup above may still be empty.
        // Carry the last detected subject across any schedule continuation
        // that has no subject code.
        let lastSubjectCode = '';
        classSchedules.forEach(schedule => {
            if (schedule.subjectCode) {
                lastSubjectCode = schedule.subjectCode;
            } else if (lastSubjectCode) {
                schedule.subjectCode = lastSubjectCode;
            }
        });

        // Calculate free time availability for Monday - Saturday
        // Morning standard window: 08:00 - 12:00 (480 - 720 min)
        // Weekday afternoon standard window: 13:00 - 17:00 (780 - 1020 min)
        // Saturday is a half-day and has no afternoon availability window.
        const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const availability = {};

        const MORNING_START = 8 * 60;   // 08:00 (480)
        const MORNING_END = 12 * 60;    // 12:00 (720)
        const AFTERNOON_START = 13 * 60;// 13:00 (780)
        const AFTERNOON_END = 17 * 60;  // 17:00 (1020)

        daysOfWeek.forEach(day => {
            const dayClasses = classSchedules.filter(c => c.days.includes(day));

            // Morning check
            const morningClasses = dayClasses.filter(c => !(c.endMin <= MORNING_START || c.startMin >= MORNING_END));
            let morningFree = morningClasses.length === 0;
            let morningStart = '08:00';
            let morningEnd = '12:00';

            if (!morningFree) {
                // Check if there is a >= 2-hour window before first morning class
                const sortedMorning = [...morningClasses].sort((a, b) => a.startMin - b.startMin);
                const firstMorning = sortedMorning[0];
                if (firstMorning.startMin - MORNING_START >= 120) {
                    morningFree = true;
                    morningStart = '08:00';
                    morningEnd = minutesTo24H(firstMorning.startMin);
                } else {
                    // Check if there is a >= 2-hour window after last morning class
                    const sortedEnd = [...morningClasses].sort((a, b) => b.endMin - a.endMin);
                    const lastMorning = sortedEnd[0];
                    if (MORNING_END - lastMorning.endMin >= 120) {
                        morningFree = true;
                        morningStart = minutesTo24H(lastMorning.endMin);
                        morningEnd = '12:00';
                    }
                }
            }

            // Afternoon check. Saturday offices are closed after 12:00.
            const isSaturday = day === 'Saturday';
            const afternoonClasses = isSaturday
                ? []
                : dayClasses.filter(c => !(c.endMin <= AFTERNOON_START || c.startMin >= AFTERNOON_END));
            let afternoonFree = !isSaturday && afternoonClasses.length === 0;
            let afternoonStart = '13:00';
            let afternoonEnd = isSaturday ? '12:00' : '17:00';

            if (!afternoonFree) {
                if (isSaturday) {
                    availability[day] = {
                        morning: {
                            free: morningFree,
                            start: morningStart,
                            end: morningEnd,
                            conflicts: morningClasses.map(c => `${c.subjectCode ? c.subjectCode + ': ' : ''}${c.displayTime}`)
                        },
                        afternoon: {
                            free: false,
                            start: '12:00',
                            end: '12:00',
                            conflicts: []
                        }
                    };
                    return;
                }

                // Check if there is a >= 2-hour window before first afternoon class (e.g. 13:00 to 17:00 when class starts at 17:00)
                const sortedAfternoon = [...afternoonClasses].sort((a, b) => a.startMin - b.startMin);
                const firstAfternoon = sortedAfternoon[0];
                if (firstAfternoon.startMin - AFTERNOON_START >= 120) {
                    afternoonFree = true;
                    afternoonStart = '13:00';
                    afternoonEnd = minutesTo24H(firstAfternoon.startMin);
                } else {
                    // Check if there is a >= 2-hour window after last afternoon class
                    const sortedEnd = [...afternoonClasses].sort((a, b) => b.endMin - a.endMin);
                    const lastAfternoon = sortedEnd[0];
                    if (AFTERNOON_END - lastAfternoon.endMin >= 120) {
                        afternoonFree = true;
                        afternoonStart = minutesTo24H(lastAfternoon.endMin);
                        afternoonEnd = '17:00';
                    }
                }
            }

            availability[day] = {
                morning: {
                    free: morningFree,
                    start: morningStart,
                    end: morningEnd,
                    conflicts: morningClasses.map(c => `${c.subjectCode ? c.subjectCode + ': ' : ''}${c.displayTime}`)
                },
                afternoon: {
                    free: afternoonFree,
                    start: afternoonStart,
                    end: afternoonEnd,
                    conflicts: afternoonClasses.map(c => `${c.subjectCode ? c.subjectCode + ': ' : ''}${c.displayTime}`)
                }
            };
        });

        return {
            studentId,
            studentName,
            schoolYear,
            term,
            course,
            classSchedules,
            availability,
            rawLines: lines
        };
    }

    /**
     * Dynamically test if a custom start/end time on a given day conflicts with class schedules
     */
    function checkSlotConflict(day, startTimeStr, endTimeStr, classSchedules) {
        const startMin = parseTimeMinutes(startTimeStr);
        const endMin = parseTimeMinutes(endTimeStr);

        if (startMin === null || endMin === null) {
            return {
                valid: false,
                isFree: false,
                message: 'Invalid time format.',
                conflicts: []
            };
        }

        if (endMin <= startMin) {
            return {
                valid: false,
                isFree: false,
                message: 'End time must be after start time.',
                conflicts: []
            };
        }

        const durationMinutes = endMin - startMin;
        const durationHours = durationMinutes / 60;
        const hasMinDuration = durationHours >= 2;

        const dayClasses = (classSchedules || []).filter(c => c.days.includes(day));
        // Overlap condition: NOT (endMin <= class.startMin OR startMin >= class.endMin)
        const overlapping = dayClasses.filter(c => !(endMin <= c.startMin || startMin >= c.endMin));

        return {
            valid: true,
            startMin,
            endMin,
            durationHours,
            hasMinDuration,
            isFree: overlapping.length === 0,
            conflicts: overlapping.map(c => `${c.subjectCode ? c.subjectCode + ': ' : ''}${c.displayTime}`)
        };
    }

    window.NuisCorParser = {
        parseNuisCorPdf,
        checkSlotConflict,
        parseTimeMinutes,
        minutesTo24H,
        minutesTo12H,
        parseDayCodes
    };

})(window);
