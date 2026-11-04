import Ajax from 'core/ajax';

const initialised = new Set();

/**
 * Waits for the same-origin PDF.js application to initialize in the iframe.
 *
 * @param {HTMLIFrameElement} iframe PDF.js viewer iframe.
 * @returns {Promise<Object>} PDF.js application instance.
 */
const waitForApplication = (iframe) => new Promise((resolve) => {
    let waiting = false;
    const interval = window.setInterval(async() => {
        if (waiting) {
            return;
        }
        try {
            const application = iframe.contentWindow?.PDFViewerApplication;
            if (!application?.initializedPromise || !application.eventBus) {
                return;
            }

            waiting = true;
            window.clearInterval(interval);
            await application.initializedPromise;
            resolve(application);
        } catch (error) {
            if (waiting) {
                resolve(null);
            }
        }
    }, 250);
});

/**
 * Waits until PDF.js has loaded the document and knows its page count.
 *
 * @param {Object} application PDF.js application instance.
 * @returns {Promise<boolean>} Whether the document became ready.
 */
const waitForDocument = (application) => new Promise((resolve) => {
    const eventBus = application.eventBus;
    let interval;
    let timeout;
    let settled = false;

    const finish = (ready) => {
        if (settled) {
            return;
        }
        settled = true;
        window.clearInterval(interval);
        window.clearTimeout(timeout);
        eventBus.off('pagesinit', check);
        eventBus.off('documentloaded', check);
        resolve(ready);
    };

    const check = () => {
        if (application.pagesCount > 0) {
            finish(true);
        }
    };

    eventBus.on('pagesinit', check);
    eventBus.on('documentloaded', check);
    interval = window.setInterval(check, 250);
    timeout = window.setTimeout(() => finish(false), 120000);
    check();
});

/**
 * Starts collecting PDF.js page changes and reporting them to Moodle.
 *
 * @param {number} cmid Course module ID.
 */
export const init = async(cmid) => {
    if (initialised.has(cmid)) {
        return;
    }
    initialised.add(cmid);

    const iframe = document.getElementById('pdf-iframe');
    if (!iframe) {
        return;
    }

    const application = await waitForApplication(iframe);
    if (!application) {
        return;
    }

    const eventBus = application.eventBus;
    const pending = new Set();
    const reported = new Set();
    let debounce;
    let flushing = false;

    // After a failed call (an expired session, the network down) retry less and less often.
    let retry = 2000;
    const scheduleFlush = () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(flush, retry);
    };

    const recordPage = (pageNumber) => {
        const number = Number(pageNumber);
        if (Number.isInteger(number) && number >= 1 && number <= application.pagesCount && !reported.has(number)) {
            pending.add(number);
            scheduleFlush();
        }
    };

    const flush = async() => {
        if (flushing || pending.size === 0 || application.pagesCount < 1) {
            return;
        }

        window.clearTimeout(debounce);
        const batch = Array.from(pending).sort((first, second) => first - second);
        batch.forEach((pageNumber) => pending.delete(pageNumber));
        flushing = true;

        try {
            await Ajax.call([{
                methodname: 'mod_pdfsecure_report_pages',
                args: {
                    cmid,
                    pages: application.pagesCount,
                    seen: batch,
                },
            }])[0];
            batch.forEach((pageNumber) => {
                reported.add(pageNumber);
                pending.delete(pageNumber);
            });
            retry = 2000;
        } catch (error) {
            batch.forEach((pageNumber) => pending.add(pageNumber));
            retry = Math.min(60000, retry * 2);
        } finally {
            flushing = false;
            if (pending.size > 0) {
                scheduleFlush();
            }
        }
    };

    // A page counts once it has stayed in front of the reader for a moment: scrolling straight to the end does not
    // read the pages in between.
    const DWELL = 1500;
    let dwell;
    const settle = (pageNumber) => {
        window.clearTimeout(dwell);
        dwell = window.setTimeout(() => recordPage(pageNumber), DWELL);
    };
    eventBus.on('pagechanging', (event) => settle(event.pageNumber));
    if (await waitForDocument(application)) {
        settle(application.page);
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            // Leaving: the page in front of the reader counts now (someone who reaches the last page and closes the
            // tab at once has read it), then everything pending goes.
            window.clearTimeout(dwell);
            recordPage(application.page);
            flush();
        }
    });
};
