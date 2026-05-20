import socket
import ssl

def check_ssl_details(hostname):
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_REQUIRED
    
    try:
        with socket.create_connection((hostname, 443), timeout=5) as sock:
            with context.wrap_socket(sock, server_hostname=hostname) as ssock:
                cert = ssock.getpeercert()
                print(f"=== SSL Certificate Details for {hostname} ===")
                subject = dict(x[0] for x in cert.get('subject', []))
                print(f"Common Name (CN): {subject.get('commonName')}")
                
                issuer = dict(x[0] for x in cert.get('issuer', []))
                print(f"Issuer: {issuer.get('commonName')}")
                
                print(f"Not Before: {cert.get('notBefore')}")
                print(f"Not After: {cert.get('notAfter')}")
                
                alt_names = [x[1] for x in cert.get('subjectAltName', [])]
                print(f"Subject Alternative Names (SANs): {alt_names}")
    except Exception as e:
        print(f"Error checking {hostname}: {e}")

check_ssl_details("n2l8studio.dk")
print()
check_ssl_details("www.n2l8studios.com")
