// Integration test for ChannelAdapter's sid-room fix (GitHub issue #303).
//
// Connects one socket.io-client to each of two independent SocketIO workers
// (channel-worker-a, channel-worker-b), both wired to a real workerman/channel
// server, and proves cross-worker delivery still works for both io.to(sid)
// and io.to(room) -- the exact behavior the fix must not break.
//
// Run via: docker compose --profile integration up -d
//          node tests/integration/channel-adapter/test.js
//          docker compose --profile integration down

const io = require('socket.io-client');

const WORKER_A_URL = process.env.WORKER_A_URL || 'http://localhost:2040';
const WORKER_B_URL = process.env.WORKER_B_URL || 'http://localhost:2041';
const TIMEOUT_MS = 15000;

let failed = false;

function fail(message) {
    failed = true;
    console.log(`FAIL: ${message}`);
}

function pass(message) {
    console.log(`PASS: ${message}`);
}

function connect(url) {
    return new Promise((resolve, reject) => {
        const socket = io(url, { forceNew: true, reconnection: false, timeout: TIMEOUT_MS });
        const timer = setTimeout(() => reject(new Error(`connect timeout: ${url}`)), TIMEOUT_MS);
        socket.on('welcome', (payload) => {
            clearTimeout(timer);
            resolve({ socket, sid: payload.sid, worker: payload.worker });
        });
        socket.on('connect_error', (err) => {
            clearTimeout(timer);
            reject(err);
        });
    });
}

function waitFor(socket, event, timeoutMessage) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(timeoutMessage)), TIMEOUT_MS);
        socket.once(event, (payload) => {
            clearTimeout(timer);
            resolve(payload);
        });
    });
}

async function testCrossWorkerSidTargeting(a, b) {
    const received = waitFor(b.socket, 'ping-received', 'cross-worker sid targeting: B never received ping-received');
    a.socket.emit('ping-sid', b.sid);
    try {
        const payload = await received;
        if (payload.from === a.sid && payload.worker === 'A') {
            pass(`cross-worker io.to(sid).emit(): A (${a.sid}) -> B (${b.sid}) delivered correctly`);
        } else {
            fail(`cross-worker sid targeting: unexpected payload ${JSON.stringify(payload)}`);
        }
    } catch (err) {
        fail(err.message);
    }
}

async function testCrossWorkerRoomBroadcast(a, b) {
    const room = 'integration-test-room';
    a.socket.emit('join-room', room);
    b.socket.emit('join-room', room);
    // Give the ChannelAdapter subscribe/local-registration a moment to land.
    await new Promise((resolve) => setTimeout(resolve, 500));

    const received = waitFor(b.socket, 'room-ping-received', 'cross-worker room broadcast: B never received room-ping-received');
    a.socket.emit('ping-room', room);
    try {
        const payload = await received;
        if (payload.from === a.sid && payload.worker === 'A') {
            pass(`cross-worker io.to(room).emit(): A -> room "${room}" -> B delivered correctly`);
        } else {
            fail(`cross-worker room broadcast: unexpected payload ${JSON.stringify(payload)}`);
        }
    } catch (err) {
        fail(err.message);
    }
}

(async () => {
    let a, b;
    try {
        [a, b] = await Promise.all([connect(WORKER_A_URL), connect(WORKER_B_URL)]);
        console.log(`Connected: A=${a.sid} (worker ${a.worker}), B=${b.sid} (worker ${b.worker})`);
    } catch (err) {
        console.log(`FAIL: could not connect to both workers: ${err.message}`);
        process.exit(1);
    }

    await testCrossWorkerSidTargeting(a, b);
    await testCrossWorkerRoomBroadcast(a, b);

    a.socket.close();
    b.socket.close();

    if (failed) {
        console.log('\nRESULT: FAILED');
        process.exit(1);
    }
    console.log('\nRESULT: ALL PASSED');
    process.exit(0);
})();
