document.addEventListener('DOMContentLoaded', function () {
    loadRooms(); // Load chat rooms when the document is ready

    // Event listener for creating a new room
    document.getElementById('createRoomButton').addEventListener('click', function () {
        const roomName = document.getElementById('roomNameInput').value;
        if (roomName.trim() !== '') {
            createRoom(roomName);
        } else {
            alert("Room name cannot be empty!");
        }
    });

    // Load messages of the selected room
    document.getElementById('roomList').addEventListener('click', function (event) {
        if (event.target && event.target.matches('.room-item')) {
            const roomId = event.target.dataset.roomId;
            loadMessages(roomId);
        }
    });

    // Send message event listener
    document.getElementById('sendMessageButton').addEventListener('click', function () {
        const messageInput = document.getElementById('messageInput');
        const roomId = document.querySelector('.active-room').dataset.roomId; // Assuming you track active room

        const message = messageInput.value;
        if (message.trim() === '') {
            alert("Message cannot be empty!");
            return;
        }

        sendMessage(roomId, message);
        messageInput.value = ''; // Clear input after sending
    });
});

function createRoom(roomName) {
    fetch("chat_api.php", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'create_room',
            room_name: roomName
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            loadRooms(); // Reload the room list
        } else {
            console.error('Error creating room:', data.message);
        }
    })
    .catch(error => console.error('Fetch error:', error));
}

function loadRooms() {
    fetch("chat_api.php", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'load_rooms'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            const roomList = document.getElementById('roomList');
            roomList.innerHTML = ''; // Clear existing rooms
            data.rooms.forEach(room => {
                const roomItem = document.createElement('div');
                roomItem.textContent = room.room_name;
                roomItem.classList.add('room-item');
                roomItem.dataset.roomId = room.id;
                roomList.appendChild(roomItem);
            });
        } else {
            console.error('Error loading rooms:', data.message);
        }
    })
    .catch(error => console.error('Fetch error:', error));
}

function loadMessages(roomId) {
    fetch("chat_api.php", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'get_messages',
            room_id: roomId
        })
    })
    .then(response => response.json())
    .then(data => {
        const messagesContainer = document.getElementById('messagesContainer');
        messagesContainer.innerHTML = ''; // Clear existing messages
        data.forEach(message => {
            const messageDiv = document.createElement('div');
            messageDiv.textContent = message.message + ' (' + message.timestamp + ')';
            messagesContainer.appendChild(messageDiv);
        });
    })
    .catch(error => console.error('Fetch error:', error));
}

function sendMessage(roomId, message) {
    fetch("chat_api.php", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'send_message',
            room_id: roomId,
            message: message
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            loadMessages(roomId); // Reload messages after sending
        } else {
            console.error('Error sending message:', data.message);
        }
    })
    .catch(error => console.error('Fetch error:', error));
}
