$(function() {
  const COLORS = [
    '#e21400', '#91580f', '#f8a700', '#f78b00',
    '#58dc00', '#287b00', '#a8f07a', '#4ae8c4',
    '#3b88eb', '#3824aa', '#a700ff', '#d300e7'
  ];

  // Initialize variables
  const $usernameInput = $('.usernameInput');
  const $roomInput = $('.roomInput');
  const $messages = $('.messages');
  const $inputMessage = $('.inputMessage');
  const $loginPage = $('.login.page');
  const $roomPage = $('.room.page');
  const $roomName = $('.roomName');
  const $memberNumber = $('.memberNumber');
  const $joinButton = $('.joinButton');
  const $leaveButton = $('.leaveButton');
  const $sendButton = $('.sendButton');
  const $memberList = $('.memberList');
  const $statusDot = $('.statusDot');

  let username;
  let currentRoom;
  let $currentInput = $usernameInput.focus();

  const socket = io('http://' + document.domain + ':2030');

  // --- Member list ---

  const setMemberList = (names) => {
    $memberList.empty();
    $memberNumber.text(names.length);
    names.forEach((name) => {
      const initials = name.substring(0, 2).toUpperCase();
      const color = getUsernameColor(name);
      const $item = $('<li class="memberItem" />').append(
        $('<span class="memberAvatar"/>').text(initials).css('background-color', color),
        $('<span class="memberName"/>').text(name).css('color', color)
      );
      $memberList.append($item);
    });
  };

  // --- Render helpers ---

  const log = (message, options) => {
    const $el = $('<li>').addClass('log').addClass(options && options.error ? 'error' : '').text(message);
    addMessageElement($el);
  };

  const addChatMessage = (data) => {
    const color = getUsernameColor(data.username);
    const $usernameDiv = $('<span class="username"/>').text(data.username).css('color', color);
    const $messageBodyDiv = $('<span class="messageBody"/>').text(data.message);
    const $messageDiv = $('<li class="message"/>').append($usernameDiv, $messageBodyDiv);
    addMessageElement($messageDiv);
  };

  const addMessageElement = (el) => {
    $messages.append(el);
    $messages[0].scrollTop = $messages[0].scrollHeight;
  };

  const getUsernameColor = (name) => {
    let hash = 7;
    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + (hash << 5) - hash;
    }
    return COLORS[Math.abs(hash % COLORS.length)];
  };

  const cleanInput = (input) => $('<div/>').text(input).html();

  // --- Join / leave ---

  const joinRoom = () => {
    username = cleanInput($usernameInput.val().trim());
    const room = cleanInput($roomInput.val().trim());
    if (!username || !room) return;
    socket.emit('join room', { username, room });
  };

  $joinButton.click(joinRoom);
  $usernameInput.on('keydown', (e) => { if (e.which === 13) $roomInput.focus(); });
  $roomInput.on('keydown', (e) => { if (e.which === 13) joinRoom(); });

  $leaveButton.click(() => socket.emit('leave room'));

  // --- Sending messages ---

  const sendMessage = () => {
    const message = cleanInput($inputMessage.val());
    if (!message || !currentRoom) return;
    $inputMessage.val('');
    addChatMessage({ username, message });
    socket.emit('room message', message);
  };

  $sendButton.click(sendMessage);
  $inputMessage.on('keydown', (e) => { if (e.which === 13) sendMessage(); });

  // --- Socket events ---

  socket.on('room joined', (data) => {
    currentRoom = data.room;
    $loginPage.hide();
    $roomPage.show();
    $roomName.text(data.room);
    setMemberList(data.members);
    $messages.empty();
    $currentInput = $inputMessage.focus();
    log(`You joined "${data.room}"`, { prepend: true });
  });

  socket.on('room left', () => {
    currentRoom = null;
    $roomPage.hide();
    $loginPage.show();
    $roomInput.val('');
    $currentInput = $roomInput.focus();
  });

  socket.on('member joined', (data) => {
    setMemberList(data.members);
    log(`${data.username} joined the room`);
  });

  socket.on('member left', (data) => {
    setMemberList(data.members);
    log(`${data.username} left the room`);
  });

  socket.on('room message', (data) => addChatMessage(data));

  socket.on('room error', (data) => log(data.message, { error: true }));

  socket.on('connect', () => $statusDot.removeClass('disconnected'));

  socket.on('disconnect', () => {
    $statusDot.addClass('disconnected');
    log('Disconnected from the server', { error: true });
  });
});
