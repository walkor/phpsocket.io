(function () {
  const statusDot = document.querySelector('.statusDot');
  const statusText = document.querySelector('.statusText');
  const logEl = document.getElementById('log');
  const clientToServerBtn = document.getElementById('clientToServerBtn');
  const serverToClientBtn = document.getElementById('serverToClientBtn');

  const socket = io('http://' + document.domain + ':2032');

  function log(tagClass, tagText, message) {
    const line = document.createElement('div');
    line.className = 'logLine';
    const tag = document.createElement('span');
    tag.className = 'tag ' + tagClass;
    tag.textContent = tagText;
    line.appendChild(tag);
    line.appendChild(document.createTextNode(message));
    logEl.appendChild(line);
    logEl.scrollTop = logEl.scrollHeight;
  }

  socket.on('connect', () => {
    statusDot.classList.remove('disconnected');
    statusText.textContent = 'connected (sid: ' + socket.id + ')';
    log('c2s', 'connect', 'Connected to server.');
  });

  socket.on('disconnect', () => {
    statusDot.classList.add('disconnected');
    statusText.textContent = 'disconnected';
    log('err', 'disconnect', 'Disconnected from server.');
  });

  // --- Client -> Server ack ---
  // We pass a callback as the last argument to emit(); the server calls it
  // to send data back to us on this same round trip.
  clientToServerBtn.addEventListener('click', () => {
    const clientTime = Date.now();
    log('c2s', 'emit', 'ping(clientTime=' + clientTime + ') -- waiting for server ack...');

    socket.emit('c2s ping', clientTime, (response) => {
      const roundTripMs = Date.now() - clientTime;
      log('c2s', 'ack', 'Server acked in ' + roundTripMs + 'ms (serverTime=' + response.serverTime + ')');
    });
  });

  // --- Server -> Client ack ---
  // We ask the server to ping us. It replies with an ack-style emit of its
  // own: socket.emit('s2c ping', data, function (ackFromClient) {...}).
  // We receive that as a normal event listener whose last argument is the
  // ack callback -- calling it sends our reply back to the server.
  serverToClientBtn.addEventListener('click', () => {
    log('s2c', 'emit', 'request server ping -- asking the server to ping us...');
    socket.emit('request server ping');
  });

  socket.on('s2c ping', (serverSentAt, ack) => {
    log('s2c', 'event', 'Server pinged us (serverSentAt=' + serverSentAt + ') -- acking back...');
    ack(Date.now());
  });

  socket.on('s2c ping result', (data) => {
    log('s2c', 'result', 'Round trip: ' + data.roundTripMs + 'ms');
  });
})();
