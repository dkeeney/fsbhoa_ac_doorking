package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"time"
)

// Config represents the structure of the JSON config file
type Config struct {
	ListenPort     string `json:"listen_port"`
	GateIP         string `json:"gate_ip"`
	ControllerName string `json:"controller_name"`
	WordPressHost  string `json:"wordpress_host"` // e.g., "access.fsbhoa.com"
}

// LogPayload represents the JSON sent to the WordPress REST API
type LogPayload struct {
	SerialNumber string `json:"SerialNumber"` // The Controller Name
	Door         int    `json:"Door"`         // 1 for DoorKing
	ZoneName     string `json:"ZoneName"`
	CardNumber   int    `json:"CardNumber"` // The swiped Wiegand tag
	Timestamp    string `json:"Timestamp"`
	Reason       int    `json:"Reason"`       // 1 for Access Granted
	EventMessage string `json:"EventMessage"` // E.g., "DoorKing Swiped Access"
	Granted      bool   `json:"Granted"`
}

const configPath = "/var/lib/fsbhoa/doorking_proxy.json"

func main() {
	// 1. Load Configuration
	config := loadConfig()

	// 2. Start the Proxy Listener
	listener, err := net.Listen("tcp", config.ListenPort)
	if err != nil {
		log.Fatalf("Error starting proxy listener on %s: %v", config.ListenPort, err)
	}
	defer listener.Close()

	log.Printf("[+] DoorKing MITM Proxy running on %s\n", config.ListenPort)
	log.Printf("[+] Target Gate IP: %s\n", config.GateIP)
	log.Printf("[+] WordPress API Target: https://%s\n", config.WordPressHost)
	log.Printf("Waiting for RAM to connect...\n")

	for {
		ramConn, err := listener.Accept()
		if err != nil {
			log.Printf("[-] Failed to accept RAM connection: %v", err)
			continue
		}
		go handleProxy(ramConn, config)
	}
}

func loadConfig() Config {
	file, err := os.Open(configPath)
	if err != nil {
		log.Fatalf("Error opening config file %s: %v", configPath, err)
	}
	defer file.Close()

	var config Config
	if err := json.NewDecoder(file).Decode(&config); err != nil {
		log.Fatalf("Error decoding JSON config: %v", err)
	}
	return config
}

func handleProxy(ramConn net.Conn, config Config) {
	defer ramConn.Close()
	log.Printf("\n[+] RAM GUI connected from %s\n", ramConn.RemoteAddr())

	// Connect to the physical gate
	gateConn, err := net.Dial("tcp", config.GateIP)
	if err != nil {
		log.Printf("[-] Failed to connect to Gate at %s: %v", config.GateIP, err)
		return
	}
	defer gateConn.Close()
	log.Printf("[+] Proxy connected to Gate at %s\n", config.GateIP)

	// Channels to manage goroutine lifecycle
	done := make(chan struct{}, 2)

	// Stream 1: RAM -> Gate (Blindly forward configuration data)
	go func() {
		io.Copy(gateConn, ramConn)
		done <- struct{}{}
	}()

	// Stream 2: Gate -> RAM (Sniff this stream for live swipes)
	go func() {
		sniffAndForward(gateConn, ramConn, config)
		done <- struct{}{}
	}()

	<-done
	log.Println("[-] Connection closed by either RAM or the Gate.")
}

func sniffAndForward(src net.Conn, dst net.Conn, config Config) {
	buf := make([]byte, 4096)
	var lineBuf []byte

	for {
		n, err := src.Read(buf)
		if n > 0 {
			// 1. Immediately forward exact bytes to RAM
			dst.Write(buf[:n])

			// 2. Inspect the bytes
			for _, b := range buf[:n] {
				if b == '\r' || b == '\n' {
					if len(lineBuf) > 5 {
						line := string(lineBuf)
						if strings.Contains(line, "/") {
							parts := strings.Fields(line)
							if len(parts) > 0 {
								// Parse tag string to integer for the API
								tagValue := parts[0]
								var cardNumber int
								fmt.Sscanf(tagValue, "%d", &cardNumber)

								log.Printf("\n>>> GATE EVENT INTERCEPTED: Tag %d\n", cardNumber)
								logEventToWordPress(cardNumber, config)
							}
						}
					}
					lineBuf = lineBuf[:0]
				} else if b >= 32 && b <= 126 {
					lineBuf = append(lineBuf, b)
				} else {
					lineBuf = lineBuf[:0]
				}
			}
		}
		if err != nil {
			break
		}
	}
}

func logEventToWordPress(cardNumber int, config Config) {
	// Construct the payload to match what WordPress expects from the UHPPOTE events
	payload := LogPayload{
		SerialNumber: config.ControllerName,
		Door:         1, // Hardcoded to 1 for DoorKing
		ZoneName:     "Entry Gate",
		CardNumber:   cardNumber,
		Timestamp:    time.Now().Format("2006-01-02 15:04:05"),
		Reason:       1, // Access Granted
		EventMessage: "DoorKing Swiped Access",
		Granted:      true,
	}

	jsonData, err := json.Marshal(payload)
	if err != nil {
		log.Printf("[-] Failed to marshal JSON payload: %v", err)
		return
	}

	// Because it's an internal service-to-service call, we use HTTP or HTTPS depending on setup.
	// For safety, we'll force HTTP to localhost to bypass firewall rules, relying on the host header.
	// Alternatively, just construct the full public HTTPS URL:
	url := fmt.Sprintf("https://%s/wp-json/fsbhoa/v1/monitor/log-event", config.WordPressHost)

	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonData))
	if err != nil {
		log.Printf("[-] Failed to create HTTP request: %v", err)
		return
	}
	req.Header.Set("Content-Type", "application/json")

	// Send the request
	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("[-] Failed to send event to WordPress API: %v", err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		log.Printf("[+] Successfully logged Tag %d to WordPress API.\n", cardNumber)
	} else {
		// Read the error message from WordPress
		body, _ := io.ReadAll(resp.Body)
		log.Printf("[-] WordPress API rejected the event. Status %d: %s\n", resp.StatusCode, string(body))
	}
}
