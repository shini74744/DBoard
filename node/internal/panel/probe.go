package panel

import (
	"context"
	"encoding/json"
	"github.com/gorilla/websocket"
	"net/http"
)

// Set once by the integrated Agent before any node service is started.
var ProbeTransport http.RoundTripper
var ProbeDial func(context.Context, string) (*websocket.Conn, *http.Response, error)
var ProbeForget func(*websocket.Conn)
var ProbeHealthy func()
var ProbeEvent func(string, json.RawMessage, func(string, json.RawMessage)) bool
