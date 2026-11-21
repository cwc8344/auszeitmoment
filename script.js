(()=>{
  const toggle=document.querySelector('.menu-toggle');
  const nav=document.querySelector('.site-nav');
  toggle?.addEventListener('click',()=>{
    const opened=nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded',String(opened));
    toggle.setAttribute('aria-label',opened?'Menü schließen':'Menü öffnen');
    toggle.textContent=opened?'×':'☰';
  });
  nav?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{
    nav.classList.remove('open');
    toggle?.setAttribute('aria-expanded','false');
    if(toggle)toggle.textContent='☰';
  }));
  document.querySelectorAll('.js-year').forEach(x=>x.textContent=new Date().getFullYear());
  // Eigene Empfänger-E-Mail-Adresse vor Veröffentlichung hier eintragen:
  const contactEmail=String(window.AUSZEITMOMENT_KONTAKT_EMAIL||'');
  const draft=(subject,body,statusElement)=>{
    const status=document.getElementById(statusElement);
    if(status)status.textContent=contactEmail
      ? 'Dein E-Mail-Programm wird geöffnet. Bitte sende die Nachricht dort ab.'
      : 'Dein E-Mail-Programm wird geöffnet. Bitte ergänze dort die Empfängeradresse und versende die Nachricht selbst.';
    window.location.href='mailto:'+contactEmail+'?subject='+encodeURIComponent(subject)+'&body='+encodeURIComponent(body);
  };
  const booking=document.getElementById('booking-form');
  booking?.addEventListener('submit',e=>{
    e.preventDefault();if(!booking.reportValidity())return;
    const fd=new FormData(booking);
    const name=fd.get('name')||'',email=fd.get('email')||'',duration=fd.get('duration')||'Noch offen';
    const date=fd.get('date')||'Nach Absprache',city=fd.get('location')||'',message=fd.get('message')||'Keine zusätzliche Nachricht';
    const body=`Hallo,

ich möchte eine mobile Massagebehandlung anfragen.

Name: ${name}
E-Mail: ${email}
Dauer: ${duration}
Wunschtag: ${date}
Ort: ${city}
Nachricht: ${message}

Viele Grüße
${name}`;
    draft('Auszeitmoment – Terminanfrage – '+name,body,'form-status');
  });
  const contact=document.getElementById('contact-form');
  contact?.addEventListener('submit',e=>{
    e.preventDefault();if(!contact.reportValidity())return;
    const fd=new FormData(contact);
    const name=fd.get('name')||'',email=fd.get('email')||'',subject=fd.get('subject')||'Frage zur mobilen Massage';
    const message=fd.get('message')||'';
    const body=`Hallo,

${message}

Viele Grüße
${name}
E-Mail für Rückfragen: ${email}`;
    draft('Auszeitmoment – '+subject,body,'contact-status');
  });
})();