$(function() {
  var FADE_TIME = 150; // ms
  var TYPING_TIMER_LENGTH = 400; // ms
  var COLORS = [
    '#e21400', '#91580f', '#f8a700', '#f78b00',
    '#58dc00', '#287b00', '#a8f07a', '#4ae8c4',
    '#3b88eb', '#3824aa', '#a700ff', '#d300e7'
  ];

  // Initialize variables
  var $window = $(window);
  var $usernameInput = $('.usernameInput');
  var $messages = $('.messages');
  var $inputMessage = $('.inputMessage');
  var $loginPage = $('.login.page');
  var $chatPage = $('.chat.page');
  var $onlineNumber = $('.onlineNumber');
  var $joinButton = $('.joinButton');
  var $sendButton = $('.sendButton');
  var $userList = $('.userList');
  var $statusDot = $('.statusDot');

  var username;
  var connected = false;
  var typing = false;
  var lastTypingTime;
  var unreadCount = 0;
  var baseTitle = document.title;
  var $currentInput = $usernameInput.focus();

  var socket = io('http://'+document.domain+':2026');

  // --- Tab title unread counter ---

  const incrementUnread = () => {
    if (document.hidden) {
      unreadCount++;
      document.title = '(' + unreadCount + ') ' + baseTitle;
    }
  }

  $(document).on('visibilitychange', () => {
    if (!document.hidden) {
      unreadCount = 0;
      document.title = baseTitle;
    }
  });

  // --- User list ---

  const addUserToList = (name) => {
    if ($userList.find('[data-username="' + name + '"]').length) return;
    var initials = name.substring(0, 2).toUpperCase();
    var color = getUsernameColor(name);
    var $item = $('<li class="userItem" />')
      .attr('data-username', name)
      .append(
        $('<span class="userAvatar"/>').text(initials).css('background-color', color),
        $('<span class="userName"/>').text(name).css('color', color)
      );
    $userList.append($item);
  }

  const removeUserFromList = (name) => {
    $userList.find('[data-username="' + name + '"]').fadeOut(200, function() {
      $(this).remove();
    });
  }

  const setUserList = (names) => {
    $userList.empty();
    $.each(names, (i, name) => addUserToList(name));
  }

  // --- Participants counter + log ---

  const addParticipantsMessage = (data) => {
    $onlineNumber.text(data.numUsers);
    var message = data.numUsers === 1 ? "there's 1 participant" : "there are " + data.numUsers + " participants";
    log(message);
  }

  // --- Timestamp ---

  const getTimestamp = () => {
    var now = new Date();
    return String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  }

  // --- Username ---

  const setUsername = () => {
    username = cleanInput($usernameInput.val().trim());
    if (username) {
      $loginPage.fadeOut();
      $chatPage.show();
      $loginPage.off('click');
      $currentInput = $inputMessage.focus();
      socket.emit('add user', username);
    }
  }

  // --- Send message ---

  const sendMessage = () => {
    var message = cleanInput($inputMessage.val());
    if (message && connected) {
      $inputMessage.val('');
      addChatMessage({ username: username, message: message });
      socket.emit('new message', message);
    }
  }

  // --- Render helpers ---

  const log = (message, options) => {
    var $el = $('<li>').addClass('log').text(message);
    addMessageElement($el, options);
  }

  const addChatMessage = (data, options) => {
    var $typingMessages = getTypingMessages(data);
    options = options || {};
    if ($typingMessages.length !== 0) {
      options.fade = false;
      $typingMessages.remove();
    }

    var color = getUsernameColor(data.username);
    var $usernameDiv = $('<span class="username"/>').text(data.username).css('color', color);
    var $messageBodyDiv = $('<span class="messageBody"/>').text(data.message);
    var $timestampDiv = $('<span class="timestamp"/>').text(data.typing ? '' : getTimestamp());

    var $messageDiv = $('<li class="message"/>')
      .data('username', data.username)
      .addClass(data.typing ? 'typing' : '')
      .append($usernameDiv, $messageBodyDiv, $timestampDiv);

    addMessageElement($messageDiv, options);
  }

  const addChatTyping = (data) => {
    data.typing = true;
    data.message = 'is typing…';
    addChatMessage(data);
  }

  const removeChatTyping = (data) => {
    getTypingMessages(data).fadeOut(function () { $(this).remove(); });
  }

  const addMessageElement = (el, options) => {
    var $el = $(el);
    options = options || {};
    if (typeof options.fade === 'undefined') options.fade = true;
    if (typeof options.prepend === 'undefined') options.prepend = false;
    if (options.fade) $el.hide().fadeIn(FADE_TIME);
    if (options.prepend) $messages.prepend($el); else $messages.append($el);
    $messages[0].scrollTop = $messages[0].scrollHeight;
  }

  const cleanInput = (input) => $('<div/>').text(input).html();

  const updateTyping = () => {
    if (connected) {
      if (!typing) { typing = true; socket.emit('typing'); }
      lastTypingTime = (new Date()).getTime();
      setTimeout(() => {
        if ((new Date()).getTime() - lastTypingTime >= TYPING_TIMER_LENGTH && typing) {
          socket.emit('stop typing');
          typing = false;
        }
      }, TYPING_TIMER_LENGTH);
    }
  }

  const getTypingMessages = (data) => {
    return $('.typing.message').filter(function() {
      return $(this).data('username') === data.username;
    });
  }

  const getUsernameColor = (username) => {
    var hash = 7;
    for (var i = 0; i < username.length; i++) {
      hash = username.charCodeAt(i) + (hash << 5) - hash;
    }
    return COLORS[Math.abs(hash % COLORS.length)];
  }

  // --- Keyboard events ---

  $window.keydown(event => {
    if (!(event.ctrlKey || event.metaKey || event.altKey)) $currentInput.focus();
    if (event.which === 13) {
      if (username) { sendMessage(); socket.emit('stop typing'); typing = false; }
      else setUsername();
    }
  });

  $inputMessage.on('input', () => updateTyping());

  // --- Click events ---

  $loginPage.click(() => $currentInput.focus());
  $joinButton.click(() => setUsername());
  $sendButton.click(() => { sendMessage(); socket.emit('stop typing'); typing = false; });
  $inputMessage.click(() => $inputMessage.focus());

  // --- Socket events ---

  socket.on('login', (data) => {
    connected = true;
    $statusDot.removeClass('disconnected').addClass('connected');
    log("Welcome to Socket.IO Chat", { prepend: true });
    addParticipantsMessage(data);
    if (data.usernames) setUserList(data.usernames);
    addUserToList(username);
  });

  socket.on('new message', (data) => {
    addChatMessage(data);
    incrementUnread();
  });

  socket.on('user joined', (data) => {
    log(data.username + ' joined');
    addParticipantsMessage(data);
    addUserToList(data.username);
  });

  socket.on('user left', (data) => {
    log(data.username + ' left');
    addParticipantsMessage(data);
    removeChatTyping(data);
    removeUserFromList(data.username);
  });

  socket.on('typing', (data) => addChatTyping(data));
  socket.on('stop typing', (data) => removeChatTyping(data));

  socket.on('disconnect', () => {
    connected = false;
    $statusDot.removeClass('connected').addClass('disconnected');
    log('you have been disconnected');
    $userList.empty();
    $onlineNumber.text(0);
  });

  socket.on('reconnect', () => {
    log('you have been reconnected');
    if (username) socket.emit('add user', username);
  });

  socket.on('reconnect_error', () => log('attempt to reconnect has failed'));
});