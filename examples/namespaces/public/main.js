(function () {
  const picker = document.querySelector('.picker');
  const connectedView = document.getElementById('connectedView');
  const connectButton = document.getElementById('connectButton');
  const switchButton = document.getElementById('switchButton');
  const nsBadge = document.getElementById('nsBadge');
  const onlineCount = document.getElementById('onlineCount');
  const messagesEl = document.getElementById('messages');
  const messageInput = document.getElementById('messageInput');
  const sendButton = document.getElementById('sendButton');
  const nsOptions = document.querySelectorAll('.nsOption');
  const nsRadios = document.querySelectorAll('input[name="ns"]');

  let socket = null;
  let currentNamespace = null;

  nsRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      nsOptions.forEach((opt) => opt.classList.remove('selected'));
      radio.closest('.nsOption').classList.add('selected');
    });
  });

  function addMessage(text, className) {
    const el = document.createElement('div');
    el.className = 'message' + (className ? ' ' + className : '');
    el.textContent = text;
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function addNsMessage(namespace, text) {
    const el = document.createElement('div');
    el.className = 'message';
    const tag = document.createElement('span');
    tag.className = 'nsTag ' + namespace.replace('/', '');
    tag.textContent = namespace;
    el.appendChild(tag);
    el.appendChild(document.createTextNode(text));
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  connectButton.addEventListener('click', () => {
    const selected = document.querySelector('input[name="ns"]:checked').value;
    currentNamespace = selected;
    messagesEl.innerHTML = '';

    socket = io('http://' + document.domain + ':2034' + selected, {
      reconnection: false,
    });

    socket.on('welcome', (data) => {
      nsBadge.textContent = data.namespace;
      onlineCount.textContent = data.online + ' online in this namespace';
      picker.style.display = 'none';
      connectedView.style.display = 'block';
      addMessage('Connected to ' + selected + '.', 'log');
    });

    socket.on('presence', (data) => {
      onlineCount.textContent = data.online + ' online in this namespace';
      addMessage('Someone joined ' + data.namespace + '.', 'log');
    });

    socket.on('message', (data) => addNsMessage(data.namespace, data.text));

    socket.on('disconnect', () => addMessage('Disconnected.', 'log'));
  });

  switchButton.addEventListener('click', () => {
    if (socket) {
      socket.disconnect();
      socket = null;
    }
    currentNamespace = null;
    connectedView.style.display = 'none';
    picker.style.display = 'block';
  });

  function sendMessage() {
    const text = messageInput.value.trim();
    if (!text || !socket) return;
    messageInput.value = '';
    addNsMessage(currentNamespace, text + ' (you)');
    socket.emit('message', text);
  }

  sendButton.addEventListener('click', sendMessage);
  messageInput.addEventListener('keydown', (e) => {
    if (e.which === 13) sendMessage();
  });
})();
