(function(){
  let recorder = null;
  let chunks = [];
  const startBtn = document.getElementById('startRecBtn');
  if (!startBtn) return;

  function canRecord() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  }

  startBtn.addEventListener('click', async function() {
    if (!canRecord()) {
      alert('Recording not supported in this browser');
      return;
    }

    if (!recorder) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recorder = new MediaRecorder(stream);
        chunks = [];
        recorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onstop = () => {
          const blob = new Blob(chunks, { type: chunks[0]?.type || 'audio/webm' });
          const filename = 'voice_' + Date.now() + (blob.type.includes('ogg') ? '.ogg' : '.webm');
          try {
            const file = new File([blob], filename, { type: blob.type });
            const dt = new DataTransfer();
            dt.items.add(file);
            // Attach to first audio input on page
            const audioInput = document.querySelector('input[type=file][name=audio]');
            if (audioInput) {
              audioInput.files = dt.files;
              alert('Voice attached. Submit the form to upload.');
            } else {
              alert('No audio input found to attach recording.');
            }
          } catch (err) {
            alert('Failed to attach recording: ' + err.message);
          }
          recorder = null;
          startBtn.textContent = 'Record Voice';
        };
        recorder.start();
        startBtn.textContent = 'Stop & Attach';
      } catch (err) {
        alert('Could not start recording: ' + err.message);
        recorder = null;
      }
    } else {
      // stop
      recorder.stop();
    }
  });
})();
